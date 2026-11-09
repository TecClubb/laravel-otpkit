<?php

namespace TecClub\OtpKit\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A code was verified (status Verified).
 */
class OtpVerified
{
    use Dispatchable;

    public function __construct(
        public readonly string $subjectKey,
        public readonly string $purpose,
        public readonly string $id,
    ) {}
}
