<?php

namespace TecClub\OtpKit\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A code reached its attempt limit and is burned.
 */
class OtpLocked
{
    use Dispatchable;

    public function __construct(
        public readonly string $subjectKey,
        public readonly string $purpose,
        public readonly string $id,
    ) {}
}
