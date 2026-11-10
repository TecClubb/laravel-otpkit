<?php

namespace TecClub\OtpKit\Login;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use TecClub\OtpKit\Enums\OtpStatus;
use TecClub\OtpKit\Results\VerificationResult;

final class LoginResult
{
    /**
     * @param  OtpStatus  $status  NotFound when no account matches or the account is blocked
     * @param  bool  $blocked  true when authorizeLoginUsing() rejected the account
     */
    public function __construct(
        public readonly OtpStatus $status,
        public readonly ?Authenticatable $user = null,
        public readonly ?string $token = null,
        public readonly ?string $tokenType = null,
        public readonly ?int $attemptsRemaining = null,
        public readonly ?int $retryAfter = null,
        public readonly bool $blocked = false,
    ) {}

    public static function fromVerification(VerificationResult $result): self
    {
        return new self($result->status, attemptsRemaining: $result->attemptsRemaining, retryAfter: $result->retryAfter);
    }

    public function ok(): bool
    {
        return $this->status === OtpStatus::Verified && ! $this->blocked && $this->user !== null;
    }

    /**
     * The same safe, generic messages as VerificationResult: a missing or
     * blocked account reads exactly like a wrong code.
     */
    public function message(): ?string
    {
        return $this->ok() ? null : (new VerificationResult($this->status, retryAfter: $this->retryAfter))->message();
    }

    /**
     * @throws ValidationException
     */
    public function throw(string $field = 'code'): self
    {
        if (! $this->ok()) {
            throw ValidationException::withMessages([$field => $this->message()]);
        }

        return $this;
    }
}
