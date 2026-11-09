<?php

namespace TecClub\OtpKit\Support;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use TecClub\OtpKit\Policy;

/**
 * The five rate limits, built on Laravel's RateLimiter and the OTP cache store
 * (the app default when otpkit.cache_store is null). Keys are hashed. IP limits
 * are skipped when there is no IP (queues, console).
 *
 * @internal
 */
class Limiter
{
    private const HOUR = 3600;

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly Repository $config,
    ) {}

    /**
     * The first send limit that blocks a new code, or null when sending is allowed.
     *
     * @return array{limit: string, retryAfter: int}|null
     */
    public function checkSend(Policy $policy, string $subjectKey, ?string $ip): ?array
    {
        $limits = $policy->rateLimits['send'];
        $limiter = $this->limiter();
        $subject = $this->subjectKey($subjectKey, $policy->purpose);

        // Sends already made this hour; drives both the cooldown step and the hourly limit.
        $sends = $limiter->attempts($subject);

        if (($retryAfter = $this->cooldownRemaining($limits['cooldown'], $sends, $subjectKey, $policy->purpose)) !== null) {
            return ['limit' => 'send.cooldown', 'retryAfter' => $retryAfter];
        }

        if ($limits['per_subject_per_hour'] !== null && $sends >= $limits['per_subject_per_hour']) {
            return ['limit' => 'send.per_subject_per_hour', 'retryAfter' => max(1, $limiter->availableIn($subject))];
        }

        if ($ip !== null && $limits['per_ip_per_hour'] !== null) {
            $key = $this->key('send:ip:'.$ip);

            if ($limiter->tooManyAttempts($key, $limits['per_ip_per_hour'])) {
                return ['limit' => 'send.per_ip_per_hour', 'retryAfter' => max(1, $limiter->availableIn($key))];
            }
        }

        return null;
    }

    /**
     * Record a successful send.
     */
    public function hitSend(Policy $policy, string $subjectKey, ?string $ip): void
    {
        $limiter = $this->limiter();

        // Always counted, even when per_subject_per_hour is null, because the cooldown steps use it.
        $limiter->hit($this->subjectKey($subjectKey, $policy->purpose), self::HOUR);
        $this->store()->put($this->lastSentKey($subjectKey, $policy->purpose), CarbonImmutable::now()->getTimestamp(), self::HOUR);

        if ($ip !== null) {
            $limiter->hit($this->key('send:ip:'.$ip), self::HOUR);
        }
    }

    /**
     * A verified code resets the cooldown steps and the per-subject count.
     */
    public function resetSend(string $subjectKey, string $purpose): void
    {
        $this->limiter()->clear($this->subjectKey($subjectKey, $purpose));
        $this->store()->forget($this->lastSentKey($subjectKey, $purpose));
    }

    /**
     * Count a verify attempt from the IP. Returns seconds to wait when limited.
     */
    public function hitVerify(Policy $policy, ?string $ip): ?int
    {
        $max = $policy->rateLimits['verify']['per_ip_per_minute'];

        if ($ip === null || $max === null) {
            return null;
        }

        $limiter = $this->limiter();
        $key = $this->key('verify:ip:'.$ip);

        if ($limiter->tooManyAttempts($key, $max)) {
            return max(1, $limiter->availableIn($key));
        }

        $limiter->hit($key, 60);

        return null;
    }

    /**
     * @param  int|list<int>|null  $cooldown
     */
    private function cooldownRemaining(int|array|null $cooldown, int $sends, string $subjectKey, string $purpose): ?int
    {
        if ($cooldown === null || $sends === 0) {
            return null;
        }

        $lastSentAt = $this->store()->get($this->lastSentKey($subjectKey, $purpose));

        if (! is_int($lastSentAt)) {
            return null;
        }

        $wait = is_int($cooldown) ? $cooldown : $cooldown[min($sends, count($cooldown)) - 1];
        $remaining = $lastSentAt + $wait - CarbonImmutable::now()->getTimestamp();

        return $remaining > 0 ? $remaining : null;
    }

    private function subjectKey(string $subjectKey, string $purpose): string
    {
        return $this->key('send:subject:'.$subjectKey.'|'.$purpose);
    }

    private function lastSentKey(string $subjectKey, string $purpose): string
    {
        return $this->key('send:last:'.$subjectKey.'|'.$purpose);
    }

    private function key(string $key): string
    {
        return 'otpkit:'.hash('sha256', $key);
    }

    private function limiter(): RateLimiter
    {
        return new RateLimiter($this->store());
    }

    private function store(): CacheRepository
    {
        return $this->cache->store($this->config->get('otpkit.cache_store'));
    }
}
