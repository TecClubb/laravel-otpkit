<?php

namespace TecClub\OtpKit\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use TecClub\OtpKit\Facades\Otp;
use TecClub\OtpKit\PendingOtp;
use TecClub\OtpKit\PolicyResolver;
use TecClub\OtpKit\Results\VerificationResult;

/**
 * Validates a code with check() by default, so a passed validation followed by
 * a failed action doesn't waste the code; the action then calls verify().
 * ->consume() makes the rule verify (and consume) instead, for one-step forms.
 *
 * Failed checks count as attempts in both modes: validate on submit, not on
 * every keystroke (Livewire wire:model.live would burn the code).
 */
class ValidOtp implements ValidationRule
{
    private bool $consume = false;

    private ?string $destination = null;

    private ?VerificationResult $result = null;

    /**
     * @param  Model|PendingOtp  $subject  a model, or a builder such as Otp::to('mail', $email)->purpose('guest')
     * @param  string  $purpose  ignored when a PendingOtp is given; it carries its own purpose
     */
    public function __construct(
        private readonly Model|PendingOtp $subject,
        private readonly string $purpose = PolicyResolver::DEFAULT_PURPOSE,
    ) {}

    public function consume(bool $consume = true): static
    {
        $this->consume = $consume;

        return $this;
    }

    /**
     * The address the code was sent to, checked when the destination binding is on.
     */
    public function destination(?string $destination): static
    {
        $this->destination = $destination;

        return $this;
    }

    /**
     * The outcome of the last validation, for logging or a precise status.
     */
    public function result(): ?VerificationResult
    {
        return $this->result;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value)) {
            $fail((string) __('otpkit::messages.invalid'));

            return;
        }

        $pending = $this->subject instanceof PendingOtp
            ? $this->subject
            : Otp::for($this->subject)->purpose($this->purpose);

        $this->result = $this->consume
            ? $pending->verify((string) $value, $this->destination)
            : $pending->check((string) $value, $this->destination);

        if (! $this->result->ok()) {
            $fail((string) $this->result->message());
        }
    }
}
