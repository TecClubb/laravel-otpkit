<?php

namespace TecClub\OtpKit\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The notification for a code was handed to Laravel.
 */
class OtpSent
{
    use Dispatchable;

    public function __construct(
        public readonly string $subjectKey,
        public readonly string $purpose,
        public readonly string $id,
    ) {}
}
