<?php

namespace TecClub\OtpKit;

use Illuminate\Contracts\Config\Repository;
use TecClub\OtpKit\Exceptions\InvalidConfigException;
use TecClub\OtpKit\Exceptions\UnknownPurposeException;
use TecClub\OtpKit\Generators\GeneratorRegistry;
use TecClub\OtpKit\Notifications\OneTimePasswordNotification;

/**
 * Package defaults, then the purpose's config entry, then per-call overrides. Later wins.
 *
 * @internal
 */
class PolicyResolver
{
    public const DEFAULT_PURPOSE = 'default';

    /** Purposes that never need listing, even with strict_purposes on. */
    public const BUILT_IN_PURPOSES = [self::DEFAULT_PURPOSE, 'login'];

    public const PURPOSE_KEYS = ['length', 'charset', 'ttl', 'max_attempts', 'bind_to', 'rate_limits', 'notification'];

    private const PACKAGE_DEFAULTS = [
        'length' => 6,
        'charset' => 'numeric',
        'ttl' => 120,
        'max_attempts' => 5,
        'bind_to' => [],
    ];

    private const PACKAGE_RATE_LIMITS = [
        'send' => [
            'cooldown' => [30, 60, 120, 300],
            'per_subject_per_hour' => 5,
            'per_ip_per_hour' => 20,
        ],
        'verify' => [
            'per_ip_per_minute' => 10,
        ],
    ];

    public function __construct(
        private readonly Repository $config,
        private readonly GeneratorRegistry $generators,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function resolve(string $purpose, array $overrides = []): Policy
    {
        $purposes = $this->config->get('otpkit.purposes', []) ?? [];

        if (! in_array($purpose, self::BUILT_IN_PURPOSES, true)
            && $this->config->get('otpkit.strict_purposes', false)
            && ! array_key_exists($purpose, $purposes)) {
            throw UnknownPurposeException::named($purpose);
        }

        $purposeConfig = $purposes[$purpose] ?? [];

        $values = array_replace(
            self::PACKAGE_DEFAULTS,
            $this->config->get('otpkit.defaults', []) ?? [],
            ['notification' => $this->config->get('otpkit.notification') ?? OneTimePasswordNotification::class],
            array_diff_key($purposeConfig, ['rate_limits' => true]),
            array_diff_key($overrides, ['rate_limits' => true]),
        );

        $values['rate_limits'] = $this->mergeRateLimits(
            self::PACKAGE_RATE_LIMITS,
            $this->config->get('otpkit.rate_limits', []) ?? [],
            $purposeConfig['rate_limits'] ?? [],
            $overrides['rate_limits'] ?? [],
        );

        return Policy::fromArray($purpose, $values, $this->generators->has(...));
    }

    /**
     * Fail early on a broken config file: resolve the defaults and every purpose.
     */
    public function validateConfig(): void
    {
        $purposes = $this->config->get('otpkit.purposes', []) ?? [];

        if (! is_array($purposes)) {
            throw InvalidConfigException::invalid('purposes', 'must be an array keyed by purpose name');
        }

        foreach ($purposes as $purpose => $settings) {
            if (! is_string($purpose) || ! is_array($settings)) {
                throw InvalidConfigException::invalid('purposes', 'must map purpose names to arrays of settings');
            }

            if (strlen($purpose) > 64) {
                throw InvalidConfigException::invalid("purposes.{$purpose}", 'name must be at most 64 characters');
            }

            if ($unknown = array_diff(array_keys($settings), self::PURPOSE_KEYS)) {
                throw InvalidConfigException::invalid("purposes.{$purpose}", 'has unknown keys ['.implode(', ', $unknown).']; allowed: '.implode(', ', self::PURPOSE_KEYS));
            }

            $this->resolve($purpose);
        }

        $this->resolve(self::DEFAULT_PURPOSE);
    }

    /**
     * Merge per section, so a list such as the cooldown steps is replaced, not merged by index.
     *
     * @param  array<string, mixed>  ...$layers
     * @return array<string, mixed>
     */
    private function mergeRateLimits(array ...$layers): array
    {
        $merged = [];

        foreach ($layers as $layer) {
            foreach ($layer as $section => $limits) {
                $merged[$section] = is_array($limits)
                    ? array_replace($merged[$section] ?? [], $limits)
                    : $limits;
            }
        }

        return $merged;
    }
}
