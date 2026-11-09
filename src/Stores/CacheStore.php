<?php

namespace TecClub\OtpKit\Stores;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use TecClub\OtpKit\Contracts\OtpStore;
use TecClub\OtpKit\Exceptions\InvalidConfigException;

/**
 * One key per active code: {prefix}:{sha256(subjectKey)}:{purpose}, kept for
 * ttl + grace seconds so verify can still report Expired. A consumed code
 * stays until then, marked consumed, so a second verify reports AlreadyUsed.
 *
 * @internal
 */
class CacheStore implements OtpStore
{
    /** Seconds a verify may hold the lock, and wait for it. */
    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'otpkit',
        private readonly int $grace = 60,
        ?string $storeName = null,
    ) {
        if (! $cache->getStore() instanceof LockProvider) {
            throw InvalidConfigException::invalid(
                'cache_store',
                'names cache store ['.($storeName ?? 'default').'] which does not support atomic locks; '
                .'use a lock-capable store such as redis, memcached, database, file or array'
            );
        }
    }

    public function put(OtpRecord $record): void
    {
        $ttl = $this->ttl($record);

        $this->cache->put($this->slotKey($record->subjectKey, $record->purpose), $this->encode($record), $ttl);
        $this->cache->put($this->idKey($record->id), [$record->subjectKey, $record->purpose], $ttl);
    }

    public function find(string $subjectKey, string $purpose): ?OtpRecord
    {
        $value = $this->cache->get($this->slotKey($subjectKey, $purpose));

        return is_array($value) ? $this->decode($value) : null;
    }

    public function atomically(string $subjectKey, string $purpose, Closure $callback): mixed
    {
        /** @var LockProvider $store */
        $store = $this->cache->getStore();

        return $store
            ->lock($this->prefix.':lock:'.hash('sha256', $subjectKey).':'.$purpose, self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, $callback);
    }

    public function incrementAttempts(OtpRecord $record): int
    {
        $current = $this->find($record->subjectKey, $record->purpose);

        if (! $current || $current->id !== $record->id) {
            return $record->attempts + 1;
        }

        $attempts = $current->attempts + 1;
        $this->rewrite($current, ['attempts' => $attempts]);

        return $attempts;
    }

    public function consume(OtpRecord $record): bool
    {
        $current = $this->find($record->subjectKey, $record->purpose);

        if (! $current || $current->id !== $record->id || $current->isConsumed()) {
            return false;
        }

        $this->rewrite($current, ['consumedAt' => CarbonImmutable::now()]);

        return true;
    }

    public function isCurrent(string $id): bool
    {
        $slot = $this->cache->get($this->idKey($id));

        if (! is_array($slot) || count($slot) !== 2) {
            return false;
        }

        $record = $this->find((string) $slot[0], (string) $slot[1]);

        return $record !== null && $record->id === $id && $record->isUsable();
    }

    public function forget(string $subjectKey, string $purpose): void
    {
        $record = $this->find($subjectKey, $purpose);

        if ($record && ! $record->isConsumed()) {
            $this->cache->forget($this->slotKey($subjectKey, $purpose));
            $this->cache->forget($this->idKey($record->id));
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function rewrite(OtpRecord $record, array $changes): void
    {
        $updated = new OtpRecord(...array_merge(get_object_vars($record), $changes));

        $this->cache->put($this->slotKey($record->subjectKey, $record->purpose), $this->encode($updated), $this->ttl($updated));
    }

    private function ttl(OtpRecord $record): int
    {
        return max(1, $record->expiresAt->getTimestamp() - CarbonImmutable::now()->getTimestamp() + $this->grace);
    }

    private function slotKey(string $subjectKey, string $purpose): string
    {
        return $this->prefix.':'.hash('sha256', $subjectKey).':'.$purpose;
    }

    private function idKey(string $id): string
    {
        return $this->prefix.':id:'.$id;
    }

    /**
     * @return array<string, mixed>
     */
    private function encode(OtpRecord $record): array
    {
        return [
            'id' => $record->id,
            'subject_key' => $record->subjectKey,
            'purpose' => $record->purpose,
            'code_hash' => $record->codeHash,
            'destination_hash' => $record->destinationHash,
            'attempts' => $record->attempts,
            'max_attempts' => $record->maxAttempts,
            'context' => $record->context,
            'expires_at' => $record->expiresAt->getTimestamp(),
            'consumed_at' => $record->consumedAt?->getTimestamp(),
            'otpable_type' => $record->otpableType,
            'otpable_id' => $record->otpableId,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function decode(array $value): OtpRecord
    {
        return new OtpRecord(
            id: $value['id'],
            subjectKey: $value['subject_key'],
            purpose: $value['purpose'],
            codeHash: $value['code_hash'],
            destinationHash: $value['destination_hash'],
            attempts: (int) $value['attempts'],
            maxAttempts: (int) $value['max_attempts'],
            context: $value['context'],
            expiresAt: CarbonImmutable::createFromTimestamp($value['expires_at']),
            consumedAt: $value['consumed_at'] !== null ? CarbonImmutable::createFromTimestamp($value['consumed_at']) : null,
            otpableType: $value['otpable_type'],
            otpableId: $value['otpable_id'],
        );
    }
}
