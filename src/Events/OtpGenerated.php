<?php

namespace TecClub\OtpKit\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A code was stored. Never carries the plaintext code.
 */
class OtpGenerated
{
    use Dispatchable;

    public function __construct(
        public readonly string $subjectKey,
        public readonly string $purpose,
        public readonly string $id,
        public readonly CarbonImmutable $expiresAt,
    ) {}
}
