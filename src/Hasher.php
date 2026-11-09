<?php

namespace TecClub\OtpKit;

use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Support\Str;

/**
 * HMAC-SHA256 keyed with the decoded APP_KEY. Codes live minutes, so the
 * attempt limit (not hash cost) is what stops guessing.
 *
 * @internal
 */
class Hasher
{
    private ?string $key = null;

    public function __construct(private readonly ?string $appKey) {}

    /**
     * Normalize user input: trimmed, uppercased, spaces and hyphens removed.
     */
    public static function normalizeCode(string $code): string
    {
        return str_replace([' ', '-'], '', strtoupper(trim($code)));
    }

    public static function normalizeAddress(string $address): string
    {
        return Str::lower(trim($address));
    }

    public function code(string $subjectKey, string $purpose, string $code): string
    {
        return $this->hmac("{$subjectKey}|{$purpose}|".self::normalizeCode($code));
    }

    public function codeMatches(string $hash, string $subjectKey, string $purpose, string $code): bool
    {
        return hash_equals($hash, $this->code($subjectKey, $purpose, $code));
    }

    public function destination(string $address): string
    {
        return $this->hmac('destination|'.self::normalizeAddress($address));
    }

    public function value(string $namespace, string $value): string
    {
        return $this->hmac("{$namespace}|{$value}");
    }

    private function hmac(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->key());
    }

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        if (! $this->appKey) {
            throw new MissingAppKeyException;
        }

        return $this->key = Str::startsWith($this->appKey, 'base64:')
            ? base64_decode(Str::after($this->appKey, 'base64:'))
            : $this->appKey;
    }
}
