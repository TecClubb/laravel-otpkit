<?php

namespace TecClub\OtpKit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use TecClub\OtpKit\Enums\OtpStatus;

/**
 * A verify or check returned any status other than Verified.
 */
class OtpFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $subjectKey,
        public readonly string $purpose,
        public readonly OtpStatus $status,
    ) {}
}
