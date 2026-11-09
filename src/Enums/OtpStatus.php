<?php

namespace TecClub\OtpKit\Enums;

enum OtpStatus: string
{
    case Verified = 'verified';
    case Invalid = 'invalid';
    case Expired = 'expired';
    case NotFound = 'not_found';
    case AlreadyUsed = 'already_used';
    case ContextMismatch = 'context_mismatch';
    case TooManyAttempts = 'too_many_attempts';
    case RateLimited = 'rate_limited';

    /**
     * The translation key used when messages are not verbose. Statuses that
     * would tell an attacker whether a code exists share one message.
     */
    public function collapsedMessageKey(): ?string
    {
        return match ($this) {
            self::Verified => null,
            self::Invalid, self::Expired, self::NotFound, self::AlreadyUsed, self::ContextMismatch => 'invalid',
            self::TooManyAttempts => 'too_many_attempts',
            self::RateLimited => 'rate_limited',
        };
    }
}
