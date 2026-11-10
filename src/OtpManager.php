<?php

namespace TecClub\OtpKit;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use TecClub\OtpKit\Contracts\OtpStore;
use TecClub\OtpKit\Events\OtpGenerated;
use TecClub\OtpKit\Events\OtpRateLimited;
use TecClub\OtpKit\Events\OtpSent;
use TecClub\OtpKit\Exceptions\MissingDestinationException;
use TecClub\OtpKit\Generators\GeneratorRegistry;
use TecClub\OtpKit\Results\IssuedOtp;
use TecClub\OtpKit\Results\SendResult;
use TecClub\OtpKit\Results\VerificationResult;
use TecClub\OtpKit\Stores\OtpRecord;
use TecClub\OtpKit\Stores\StoreManager;
use TecClub\OtpKit\Support\Limiter;
use TecClub\OtpKit\Support\RequestContext;

/**
 * The service behind the Otp facade.
 */
class OtpManager
{
    /**
     * @internal Resolve it from the container or use the Otp facade.
     */
    public function __construct(
        protected readonly Container $container,
        protected readonly Repository $config,
        protected readonly PolicyResolver $policies,
        protected readonly GeneratorRegistry $generators,
        protected readonly Hasher $hasher,
        protected readonly Verifier $verifier,
        protected readonly RequestContext $request,
        protected readonly Dispatcher $events,
        protected readonly Limiter $limiter,
    ) {}

    /**
     * Start a code for a model subject.
     */
    public function for(Model $model): PendingOtp
    {
        return new PendingOtp($this, Subject::fromModel($model));
    }

    /**
     * Start a code for an address that has no model, such as a guest email.
     */
    public function to(string $channel, string $address): PendingOtp
    {
        return new PendingOtp($this, Subject::fromDestination($channel, $address));
    }

    /**
     * Whether the code is still the active, usable one. Used by queued notifications.
     */
    public function isCurrent(string $id): bool
    {
        return $this->store()->isCurrent($id);
    }

    public function store(): OtpStore
    {
        return $this->container->make(StoreManager::class)->driver();
    }

    /**
     * @internal
     */
    public function send(PendingOtp $pending): SendResult
    {
        $policy = $this->policyFor($pending);
        $notifiable = $this->notifiableFor($pending);
        $subjectKey = $pending->subject->key;
        $ip = $this->request->ip();

        // A rate-limited send creates no code and sends nothing.
        if ($limited = $this->limiter->checkSend($policy, $subjectKey, $ip)) {
            $this->events->dispatch(new OtpRateLimited($subjectKey, $policy->purpose, $limited['limit'], $ip));

            return SendResult::rateLimited($limited['retryAfter']);
        }

        $issued = $this->issue($pending, $policy);

        // Custom notification classes receive the code as the $otp constructor argument.
        $notification = $this->container->make($policy->notification, ['otp' => $issued]);

        $this->dispatchNotification($pending, $issued, $notifiable, $notification);

        $this->limiter->hitSend($policy, $subjectKey, $ip);

        $this->events->dispatch(new OtpSent($subjectKey, $policy->purpose, $issued->id));

        return SendResult::forIssued($issued);
    }

    /**
     * @internal
     */
    public function generate(PendingOtp $pending): IssuedOtp
    {
        return $this->issue($pending, $this->policyFor($pending));
    }

    /**
     * @internal
     */
    public function verify(PendingOtp $pending, string $code, ?string $destination, bool $consume): VerificationResult
    {
        return $this->verifier->verify(
            $this->store(),
            $pending->subject,
            $this->policyFor($pending),
            $code,
            $destination,
            $consume,
        );
    }

    /**
     * @internal
     */
    public function pending(PendingOtp $pending): bool
    {
        return (bool) $this->store()->find($pending->subject->key, $pending->getPurpose())?->isUsable();
    }

    /**
     * @internal
     */
    public function invalidate(PendingOtp $pending): void
    {
        $this->store()->forget($pending->subject->key, $pending->getPurpose());
    }

    protected function policyFor(PendingOtp $pending): Policy
    {
        return $this->policies->resolve($pending->getPurpose(), $pending->getOverrides());
    }

    protected function issue(PendingOtp $pending, Policy $policy): IssuedOtp
    {
        $subject = $pending->subject;
        $code = $this->makeCode($policy);
        $expiresAt = CarbonImmutable::now()->addSeconds($policy->ttl);
        $destination = $pending->getDestinationAddress();

        $record = new OtpRecord(
            id: (string) Str::ulid(),
            subjectKey: $subject->key,
            purpose: $policy->purpose,
            codeHash: $this->hasher->code($subject->key, $policy->purpose, $code),
            destinationHash: $destination !== null ? $this->hasher->destination($destination) : null,
            attempts: 0,
            maxAttempts: $policy->maxAttempts,
            context: $this->contextFor($policy),
            expiresAt: $expiresAt,
            otpableType: $subject->model?->getMorphClass(),
            otpableId: $subject->model?->getKey(),
        );

        $this->store()->put($record);

        $this->events->dispatch(new OtpGenerated($subject->key, $policy->purpose, $record->id, $expiresAt));

        return new IssuedOtp($record->id, $code, $policy->purpose, $policy->length, $expiresAt);
    }

    protected function makeCode(Policy $policy): string
    {
        return $this->generators->get($policy->charset)->generate($policy->length);
    }

    /**
     * Snapshot the bindings, plus hashed IP and user agent when those are bound.
     *
     * @return array{bind: list<string>, ip?: ?string, user_agent?: ?string}|null
     */
    protected function contextFor(Policy $policy): ?array
    {
        if ($policy->bindTo === []) {
            return null;
        }

        $context = ['bind' => $policy->bindTo];

        if ($policy->binds('ip')) {
            $ip = $this->request->ip();
            $context['ip'] = $ip !== null ? $this->hasher->value('ip', $ip) : null;
        }

        if ($policy->binds('user_agent')) {
            $userAgent = $this->request->userAgent();
            $context['user_agent'] = $userAgent !== null ? $this->hasher->value('user_agent', $userAgent) : null;
        }

        return $context;
    }

    /**
     * Resolved before the code is stored, so a missing destination stores nothing.
     */
    protected function notifiableFor(PendingOtp $pending): object
    {
        $subject = $pending->subject;

        if ($pending->getDestinationAddress() !== null) {
            return (new AnonymousNotifiable)->route((string) $pending->getDestinationChannel(), $pending->getDestinationAddress());
        }

        if ($subject->address !== null) {
            return (new AnonymousNotifiable)->route((string) $subject->channel, $subject->address);
        }

        $model = $subject->model;

        if ($model !== null && method_exists($model, 'notify')) {
            return $model;
        }

        throw MissingDestinationException::forModel($model ?? throw new \LogicException('OtpKit subject has neither a model nor an address.'));
    }

    protected function dispatchNotification(PendingOtp $pending, IssuedOtp $issued, object $notifiable, Notification $notification): void
    {
        if ($this->config->get('otpkit.queue.enabled', true)) {
            $notifiable->notify($notification);
        } else {
            $notifiable->notifyNow($notification);
        }
    }
}
