<?php

namespace TecClub\OtpKit\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A send or verify was rate limited.
 */
class OtpRateLimited
{
    use Dispatchable;

    public function __construct(
        public readonly string $subjectKey,
        public readonly string $purpose,
        public readonly string $limit,
        public readonly ?string $ip,
    ) {}
}
