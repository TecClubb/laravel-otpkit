<?php

namespace TecClub\OtpKit;

use Illuminate\Contracts\Events\Dispatcher;
use TecClub\OtpKit\Contracts\OtpStore;
use TecClub\OtpKit\Enums\OtpStatus;
use TecClub\OtpKit\Events\OtpFailed;
use TecClub\OtpKit\Events\OtpLocked;
use TecClub\OtpKit\Events\OtpRateLimited;
use TecClub\OtpKit\Events\OtpVerified;
use TecClub\OtpKit\Results\VerificationResult;
use TecClub\OtpKit\Stores\OtpRecord;
use TecClub\OtpKit\Support\Limiter;
use TecClub\OtpKit\Support\RequestContext;

/**
 * The verification flow: seven checks, eight outcomes. Only Verified consumes the code.
 *
 * @internal
 */
class Verifier
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly Limiter $limiter,
        private readonly RequestContext $request,
        private readonly Dispatcher $events,
    ) {}

    public function verify(
        OtpStore $store,
        Subject $subject,
        Policy $policy,
        string $code,
        ?string $destination,
        bool $consume,
    ): VerificationResult {
        $purpose = $policy->purpose;
        $ip = $this->request->ip();

        // 1. Per-IP verify limit, before touching the code.
        if (($retryAfter = $this->limiter->hitVerify($policy, $ip)) !== null) {
            $this->events->dispatch(new OtpRateLimited($subject->key, $purpose, 'verify.per_ip_per_minute', $ip));

            return $this->failed($subject, $purpose, new VerificationResult(OtpStatus::RateLimited, retryAfter: $retryAfter));
        }

        $locked = null;
        $verified = null;

        $result = $store->atomically($subject->key, $purpose, function () use ($store, $subject, $purpose, $code, $destination, $consume, &$locked, &$verified) {
            // 2. An active code exists.
            $record = $store->find($subject->key, $purpose);

            if (! $record) {
                return new VerificationResult(OtpStatus::NotFound);
            }

            // 3. Not already used, including by a parallel request.
            if ($record->isConsumed()) {
                return new VerificationResult(OtpStatus::AlreadyUsed);
            }

            // 4. Not expired.
            if ($record->isExpired()) {
                return new VerificationResult(OtpStatus::Expired);
            }

            // 5. Attempts left.
            if ($record->isLocked()) {
                return new VerificationResult(OtpStatus::TooManyAttempts, attemptsRemaining: 0);
            }

            // 6. Bindings, then 7. the code itself. A failure of either uses up an attempt.
            $bindingsPass = $this->bindingsPass($record, $destination);

            if (! $bindingsPass || ! $this->hasher->codeMatches($record->codeHash, $subject->key, $purpose, $code)) {
                $attempts = $store->incrementAttempts($record);
                $remaining = max(0, $record->maxAttempts - $attempts);

                if ($remaining === 0) {
                    $locked = $record;

                    return new VerificationResult(OtpStatus::TooManyAttempts, attemptsRemaining: 0);
                }

                return new VerificationResult(
                    $bindingsPass ? OtpStatus::Invalid : OtpStatus::ContextMismatch,
                    attemptsRemaining: $remaining,
                );
            }

            if ($consume && ! $store->consume($record)) {
                return new VerificationResult(OtpStatus::AlreadyUsed);
            }

            $verified = $record;

            return new VerificationResult(OtpStatus::Verified, attemptsRemaining: $record->maxAttempts - $record->attempts);
        });

        if ($locked instanceof OtpRecord) {
            $this->events->dispatch(new OtpLocked($subject->key, $purpose, $locked->id));
        }

        if ($result->ok()) {
            if ($consume && $verified instanceof OtpRecord) {
                $this->events->dispatch(new OtpVerified($subject->key, $purpose, $verified->id));
            }

            return $result;
        }

        return $this->failed($subject, $purpose, $result);
    }

    private function failed(Subject $subject, string $purpose, VerificationResult $result): VerificationResult
    {
        $this->events->dispatch(new OtpFailed($subject->key, $purpose, $result->status));

        return $result;
    }

    /**
     * Bindings are snapshotted onto the code when it is issued, so later config
     * changes never affect codes already sent.
     */
    private function bindingsPass(OtpRecord $record, ?string $destination): bool
    {
        $bindings = $record->bindings();

        if (in_array('destination', $bindings, true) && $record->destinationHash !== null) {
            if ($destination === null || ! hash_equals($record->destinationHash, $this->hasher->destination($destination))) {
                return false;
            }
        }

        foreach (['ip' => $this->request->ip(), 'user_agent' => $this->request->userAgent()] as $binding => $current) {
            $expected = $record->context[$binding] ?? null;

            if (! in_array($binding, $bindings, true) || $expected === null) {
                continue;
            }

            if ($current === null || ! hash_equals($expected, $this->hasher->value($binding, $current))) {
                return false;
            }
        }

        return true;
    }
}
