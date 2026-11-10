<?php

namespace TecClub\OtpKit;

use Illuminate\Notifications\Notification;
use TecClub\OtpKit\Enums\Charset;
use TecClub\OtpKit\Results\IssuedOtp;
use TecClub\OtpKit\Results\SendResult;
use TecClub\OtpKit\Results\VerificationResult;

/**
 * Fluent builder for one subject and purpose.
 */
class PendingOtp
{
    private string $purpose = PolicyResolver::DEFAULT_PURPOSE;

    /** @var array<string, mixed> */
    private array $overrides = [];

    private ?string $destinationChannel = null;

    private ?string $destinationAddress = null;

    /**
     * @internal Start one with Otp::for(), Otp::to() or $model->otp().
     */
    public function __construct(
        private readonly OtpManager $manager,
        public readonly Subject $subject,
    ) {}

    public function purpose(string $purpose): static
    {
        $this->purpose = $purpose;

        return $this;
    }

    public function length(int $length): static
    {
        return $this->override('length', $length);
    }

    public function charset(Charset|string $charset): static
    {
        return $this->override('charset', $charset instanceof Charset ? $charset->value : $charset);
    }

    public function numeric(): static
    {
        return $this->charset(Charset::Numeric);
    }

    public function alphanumeric(): static
    {
        return $this->charset(Charset::Alphanumeric);
    }

    /**
     * Lifetime in seconds.
     */
    public function ttl(int $seconds): static
    {
        return $this->override('ttl', $seconds);
    }

    public function maxAttempts(int $attempts): static
    {
        return $this->override('max_attempts', $attempts);
    }

    /**
     * @param  list<string>  $bindings  any of "destination", "ip", "user_agent"
     */
    public function bindTo(array $bindings): static
    {
        return $this->override('bind_to', $bindings);
    }

    /**
     * @param  class-string<Notification>  $notification
     */
    public function notification(string $notification): static
    {
        return $this->override('notification', $notification);
    }

    /**
     * Deliver the code somewhere other than the subject. Bind "destination"
     * for the purpose so the code can only confirm this address.
     */
    public function sendTo(string $channel, string $address): static
    {
        $this->destinationChannel = $channel;
        $this->destinationAddress = trim($address);

        return $this;
    }

    public function send(): SendResult
    {
        return $this->manager->send($this);
    }

    /**
     * Store a code and return it without sending anything.
     */
    public function generate(): IssuedOtp
    {
        return $this->manager->generate($this);
    }

    /**
     * Run every check and consume the code on success.
     */
    public function verify(string $code, ?string $destination = null): VerificationResult
    {
        return $this->manager->verify($this, $code, $destination, consume: true);
    }

    /**
     * Run every check and count failed attempts, but leave the code active.
     */
    public function check(string $code, ?string $destination = null): VerificationResult
    {
        return $this->manager->verify($this, $code, $destination, consume: false);
    }

    /**
     * Whether an active, usable code exists.
     */
    public function pending(): bool
    {
        return $this->manager->pending($this);
    }

    /**
     * Kill the active code.
     */
    public function invalidate(): void
    {
        $this->manager->invalidate($this);
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function getOverrides(): array
    {
        return $this->overrides;
    }

    /**
     * @internal
     */
    public function getDestinationChannel(): ?string
    {
        return $this->destinationChannel;
    }

    /**
     * @internal
     */
    public function getDestinationAddress(): ?string
    {
        return $this->destinationAddress;
    }

    private function override(string $key, mixed $value): static
    {
        $this->overrides[$key] = $value;

        return $this;
    }
}
