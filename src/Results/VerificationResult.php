<?php

namespace TecClub\OtpKit\Results;

use Illuminate\Validation\ValidationException;
use TecClub\OtpKit\Enums\OtpStatus;

final class VerificationResult
{
    public function __construct(
        public readonly OtpStatus $status,
        public readonly ?int $attemptsRemaining = null,
        public readonly ?int $retryAfter = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === OtpStatus::Verified;
    }

    /**
     * A safe, translated, user-facing message; null when verified.
     *
     * Unless otpkit.messages.verbose is on, statuses that would reveal whether
     * a code exists share one message. The status itself stays precise.
     */
    public function message(): ?string
    {
        if ($this->ok()) {
            return null;
        }

        $key = config('otpkit.messages.verbose', false)
            ? 'verbose.'.$this->status->value
            : $this->status->collapsedMessageKey();

        return (string) __("otpkit::messages.{$key}", ['seconds' => $this->retryAfter ?? 0]);
    }

    /**
     * Throw a ValidationException on the given field when not ok.
     *
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
