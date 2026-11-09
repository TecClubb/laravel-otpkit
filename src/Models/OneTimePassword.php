<?php

namespace TecClub\OtpKit\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $subject_key
 * @property string|null $otpable_type
 * @property int|string|null $otpable_id
 * @property string $purpose
 * @property string $code_hash
 * @property string|null $destination_hash
 * @property int $attempts
 * @property int $max_attempts
 * @property array<string, mixed>|null $context
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 */
class OneTimePassword extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['code_hash', 'destination_hash'];

    public function getTable(): string
    {
        return config('otpkit.database.table', 'one_time_passwords');
    }

    public function getConnectionName(): ?string
    {
        return config('otpkit.database.connection') ?? parent::getConnectionName();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function otpable(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'context' => 'array',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }
}
