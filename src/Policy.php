<?php

namespace TecClub\OtpKit;

use Illuminate\Notifications\Notification;
use TecClub\OtpKit\Exceptions\InvalidConfigException;

/**
 * The resolved, immutable rules for one code.
 *
 * @internal Configure policies in config/otpkit.php or per call on PendingOtp.
 */
final class Policy
{
    public const MIN_LENGTH = 4;

    public const MAX_LENGTH = 12;

    public const BINDINGS = ['destination', 'ip', 'user_agent'];

    /**
     * @param  list<string>  $bindTo
     * @param  array{send: array{cooldown: int|list<int>|null, per_subject_per_hour: ?int, per_ip_per_hour: ?int}, verify: array{per_ip_per_minute: ?int}}  $rateLimits
     * @param  class-string<Notification>  $notification
     */
    public function __construct(
        public readonly string $purpose,
        public readonly int $length,
        public readonly string $charset,
        public readonly int $ttl,
        public readonly int $maxAttempts,
        public readonly array $bindTo,
        public readonly array $rateLimits,
        public readonly string $notification,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @param  callable(string): bool  $charsetExists
     */
    public static function fromArray(string $purpose, array $values, callable $charsetExists): self
    {
        $length = $values['length'] ?? null;
        if (! is_int($length) || $length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw InvalidConfigException::invalid('length', 'must be an integer between '.self::MIN_LENGTH.' and '.self::MAX_LENGTH, $purpose);
        }

        $charset = $values['charset'] ?? null;
        if (! is_string($charset) || ! $charsetExists($charset)) {
            throw InvalidConfigException::invalid('charset', 'must be "numeric", "alphanumeric" or a registered generator name', $purpose);
        }

        $ttl = $values['ttl'] ?? null;
        if (! is_int($ttl) || $ttl < 1) {
            throw InvalidConfigException::invalid('ttl', 'must be a positive number of seconds', $purpose);
        }

        $maxAttempts = $values['max_attempts'] ?? null;
        if (! is_int($maxAttempts) || $maxAttempts < 1 || $maxAttempts > 255) {
            throw InvalidConfigException::invalid('max_attempts', 'must be an integer between 1 and 255', $purpose);
        }

        $bindTo = $values['bind_to'] ?? [];
        if (! is_array($bindTo) || array_diff($bindTo, self::BINDINGS) !== []) {
            throw InvalidConfigException::invalid('bind_to', 'may only contain '.implode(', ', self::BINDINGS), $purpose);
        }

        $notification = $values['notification'] ?? null;
        if (! is_string($notification) || ! is_a($notification, Notification::class, true)) {
            throw InvalidConfigException::invalid('notification', 'must be a class extending '.Notification::class, $purpose);
        }

        return new self(
            purpose: $purpose,
            length: $length,
            charset: $charset,
            ttl: $ttl,
            maxAttempts: $maxAttempts,
            bindTo: array_values(array_unique($bindTo)),
            rateLimits: self::validateRateLimits($values['rate_limits'] ?? [], $purpose),
            notification: $notification,
        );
    }

    public function binds(string $binding): bool
    {
        return in_array($binding, $this->bindTo, true);
    }

    /**
     * @return array{send: array{cooldown: int|list<int>|null, per_subject_per_hour: ?int, per_ip_per_hour: ?int}, verify: array{per_ip_per_minute: ?int}}
     */
    private static function validateRateLimits(mixed $limits, string $purpose): array
    {
        if (! is_array($limits)) {
            throw InvalidConfigException::invalid('rate_limits', 'must be an array', $purpose);
        }

        $positive = fn (mixed $value) => $value === null || (is_int($value) && $value >= 0);

        $send = $limits['send'] ?? [];
        $verify = $limits['verify'] ?? [];

        $cooldown = $send['cooldown'] ?? null;
        $cooldownValid = $positive($cooldown)
            || (is_array($cooldown) && $cooldown !== [] && array_is_list($cooldown) && array_filter($cooldown, fn ($step) => ! is_int($step) || $step < 0) === []);

        if (! $cooldownValid) {
            throw InvalidConfigException::invalid('rate_limits.send.cooldown', 'must be null, a number of seconds, or a list of seconds', $purpose);
        }

        foreach (['send.per_subject_per_hour' => $send['per_subject_per_hour'] ?? null, 'send.per_ip_per_hour' => $send['per_ip_per_hour'] ?? null, 'verify.per_ip_per_minute' => $verify['per_ip_per_minute'] ?? null] as $key => $value) {
            if (! $positive($value)) {
                throw InvalidConfigException::invalid("rate_limits.{$key}", 'must be null or a non-negative integer', $purpose);
            }
        }

        return [
            'send' => [
                'cooldown' => $cooldown,
                'per_subject_per_hour' => $send['per_subject_per_hour'] ?? null,
                'per_ip_per_hour' => $send['per_ip_per_hour'] ?? null,
            ],
            'verify' => [
                'per_ip_per_minute' => $verify['per_ip_per_minute'] ?? null,
            ],
        ];
    }
}
