<?php

namespace TecClub\OtpKit\Results;

use Carbon\CarbonImmutable;

/**
 * A freshly issued code. The only place the plaintext code exists besides the notification.
 */
final class IssuedOtp
{
    public function __construct(
        public readonly string $id,
        #[\SensitiveParameter]
        public readonly string $code,
        public readonly string $purpose,
        public readonly int $length,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    /**
     * Whole minutes until expiry, rounded up and at least 1.
     */
    public function expiresInMinutes(): int
    {
        $seconds = $this->expiresAt->getTimestamp() - CarbonImmutable::now()->getTimestamp();

        return max(1, (int) ceil($seconds / 60));
    }
}
