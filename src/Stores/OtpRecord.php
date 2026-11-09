<?php

namespace TecClub\OtpKit\Stores;

use Carbon\CarbonImmutable;

/**
 * A stored code. Never holds the plaintext code, only its HMAC.
 */
final class OtpRecord
{
    /**
     * @param  array{bind?: list<string>, ip?: ?string, user_agent?: ?string}|null  $context
     */
    public function __construct(
        public readonly string $id,
        public readonly string $subjectKey,
        public readonly string $purpose,
        public readonly string $codeHash,
        public readonly ?string $destinationHash,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly ?array $context,
        public readonly CarbonImmutable $expiresAt,
        public readonly ?CarbonImmutable $consumedAt = null,
        public readonly ?string $otpableType = null,
        public readonly int|string|null $otpableId = null,
    ) {}

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return $this->expiresAt->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }

    public function isLocked(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }

    public function isUsable(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired() && ! $this->isLocked();
    }

    /**
     * @return list<string>
     */
    public function bindings(): array
    {
        return $this->context['bind'] ?? [];
    }
}
