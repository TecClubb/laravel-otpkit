<?php

namespace TecClub\OtpKit\Stores;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use TecClub\OtpKit\Contracts\OtpStore;
use TecClub\OtpKit\Models\OneTimePassword;

/**
 * @internal
 */
class DatabaseStore implements OtpStore
{
    private int $lockDepth = 0;

    public function put(OtpRecord $record): void
    {
        $this->connection()->transaction(function () use ($record) {
            $this->query()
                ->where('subject_key', $record->subjectKey)
                ->where('purpose', $record->purpose)
                ->whereNull('consumed_at')
                ->delete();

            $this->newModel()->forceFill([
                'id' => $record->id,
                'subject_key' => $record->subjectKey,
                'otpable_type' => $record->otpableType,
                'otpable_id' => $record->otpableId,
                'purpose' => $record->purpose,
                'code_hash' => $record->codeHash,
                'destination_hash' => $record->destinationHash,
                'attempts' => $record->attempts,
                'max_attempts' => $record->maxAttempts,
                'context' => $record->context,
                'expires_at' => $record->expiresAt,
                'consumed_at' => $record->consumedAt,
            ])->save();
        });
    }

    public function find(string $subjectKey, string $purpose): ?OtpRecord
    {
        $query = $this->query()
            ->where('subject_key', $subjectKey)
            ->where('purpose', $purpose)
            ->orderByDesc('id');

        if ($this->lockDepth > 0) {
            $query->lockForUpdate();
        }

        $model = $query->first();

        return $model ? $this->toRecord($model) : null;
    }

    public function atomically(string $subjectKey, string $purpose, Closure $callback): mixed
    {
        return $this->connection()->transaction(function () use ($callback) {
            $this->lockDepth++;

            try {
                return $callback();
            } finally {
                $this->lockDepth--;
            }
        });
    }

    public function incrementAttempts(OtpRecord $record): int
    {
        $this->query()->whereKey($record->id)->increment('attempts');

        return $record->attempts + 1;
    }

    public function consume(OtpRecord $record): bool
    {
        return $this->query()
            ->whereKey($record->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => CarbonImmutable::now()]) === 1;
    }

    public function isCurrent(string $id): bool
    {
        $model = $this->query()->whereKey($id)->first();

        if (! $model) {
            return false;
        }

        $record = $this->toRecord($model);

        if (! $record->isUsable()) {
            return false;
        }

        // A newer code for the same subject and purpose replaces this one.
        return ! $this->query()
            ->where('subject_key', $record->subjectKey)
            ->where('purpose', $record->purpose)
            ->where('id', '>', $record->id)
            ->exists();
    }

    public function forget(string $subjectKey, string $purpose): void
    {
        $this->query()
            ->where('subject_key', $subjectKey)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->delete();
    }

    /**
     * @return Builder<OneTimePassword>
     */
    protected function query(): Builder
    {
        return $this->newModel()->newQuery();
    }

    protected function newModel(): OneTimePassword
    {
        return new OneTimePassword;
    }

    protected function connection(): ConnectionInterface
    {
        return $this->newModel()->getConnection();
    }

    protected function toRecord(OneTimePassword $model): OtpRecord
    {
        return new OtpRecord(
            id: $model->id,
            subjectKey: $model->subject_key,
            purpose: $model->purpose,
            codeHash: $model->code_hash,
            destinationHash: $model->destination_hash,
            attempts: $model->attempts,
            maxAttempts: $model->max_attempts,
            context: $model->context,
            expiresAt: $model->expires_at,
            consumedAt: $model->consumed_at,
            otpableType: $model->otpable_type,
            otpableId: $model->otpable_id,
        );
    }
}
