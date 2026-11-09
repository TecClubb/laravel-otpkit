<?php

namespace TecClub\OtpKit\Support;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use TecClub\OtpKit\Policy;

/**
 * Rate limits built on Laravel's RateLimiter, using the OTP cache store when
 * set. Keys are hashed.
 *
 * @internal
 */
class Limiter
{
    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Repository $config,
    ) {}

    /**
     * Count a verify attempt from the IP. Returns seconds to wait when limited.
     */
    public function hitVerify(Policy $policy, ?string $ip): ?int
    {
        $max = $policy->rateLimits['verify']['per_ip_per_minute'];

        if ($ip === null || $max === null) {
            return null;
        }

        return $this->attempt('verify:ip:'.$ip, $max, 60);
    }

    /**
     * Returns seconds to wait when the key is over its limit, otherwise counts a hit.
     */
    private function attempt(string $key, int $max, int $decaySeconds): ?int
    {
        $limiter = $this->limiter();
        $key = 'otpkit:'.hash('sha256', $key);

        if ($limiter->tooManyAttempts($key, $max)) {
            return max(1, $limiter->availableIn($key));
        }

        $limiter->hit($key, $decaySeconds);

        return null;
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter($this->cache->store($this->config->get('otpkit.cache_store')));
    }
}
