<?php

namespace TecClub\OtpKit\Results;

use Carbon\CarbonImmutable;
use TecClub\OtpKit\Enums\SendStatus;

final class SendResult
{
    public function __construct(
        public readonly SendStatus $status,
        public readonly ?int $retryAfter = null,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?string $id = null,
    ) {}

    public static function forIssued(IssuedOtp $issued): self
    {
        return new self(SendStatus::Sent, expiresAt: $issued->expiresAt, id: $issued->id);
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self(SendStatus::RateLimited, retryAfter: $retryAfter);
    }

    public function sent(): bool
    {
        return $this->status === SendStatus::Sent;
    }
}
