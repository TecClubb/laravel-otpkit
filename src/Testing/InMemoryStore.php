<?php

namespace TecClub\OtpKit\Testing;

use Carbon\CarbonImmutable;
use Closure;
use TecClub\OtpKit\Contracts\OtpStore;
use TecClub\OtpKit\Stores\OtpRecord;

/**
 * A process-local store used by Otp::fake().
 *
 * @internal
 */
class InMemoryStore implements OtpStore
{
    /** @var array<string, OtpRecord> latest record per subject and purpose */
    private array $records = [];

    /** @var array<string, string> id => subject|purpose */
    private array $index = [];

    public function put(OtpRecord $record): void
    {
        $slot = $this->slot($record->subjectKey, $record->purpose);

        $this->records[$slot] = $record;
        $this->index[$record->id] = $slot;
    }

    public function find(string $subjectKey, string $purpose): ?OtpRecord
    {
        return $this->records[$this->slot($subjectKey, $purpose)] ?? null;
    }

    public function atomically(string $subjectKey, string $purpose, Closure $callback): mixed
    {
        return $callback();
    }

    public function incrementAttempts(OtpRecord $record): int
    {
        $this->replace($record, ['attempts' => $record->attempts + 1]);

        return $record->attempts + 1;
    }

    public function consume(OtpRecord $record): bool
    {
        $current = $this->current($record->id);

        if (! $current || $current->isConsumed()) {
            return false;
        }

        $this->replace($current, ['consumedAt' => CarbonImmutable::now()]);

        return true;
    }

    public function isCurrent(string $id): bool
    {
        return (bool) $this->current($id)?->isUsable();
    }

    public function forget(string $subjectKey, string $purpose): void
    {
        $slot = $this->slot($subjectKey, $purpose);

        if (isset($this->records[$slot]) && ! $this->records[$slot]->isConsumed()) {
            unset($this->records[$slot]);
        }
    }

    private function current(string $id): ?OtpRecord
    {
        $slot = $this->index[$id] ?? null;
        $record = $slot !== null ? ($this->records[$slot] ?? null) : null;

        return $record?->id === $id ? $record : null;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function replace(OtpRecord $record, array $changes): void
    {
        $current = $this->current($record->id);

        if (! $current) {
            return;
        }

        $this->records[$this->index[$record->id]] = new OtpRecord(...array_merge(get_object_vars($current), $changes));
    }

    private function slot(string $subjectKey, string $purpose): string
    {
        return $subjectKey.'|'.$purpose;
    }
}
