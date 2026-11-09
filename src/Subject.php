<?php

namespace TecClub\OtpKit;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Who a code belongs to: an Eloquent model, or an address when no account exists.
 */
final class Subject
{
    private function __construct(
        public readonly string $key,
        public readonly ?Model $model = null,
        public readonly ?string $channel = null,
        public readonly ?string $address = null,
    ) {}

    public static function fromModel(Model $model): self
    {
        $key = $model->getKey();

        if ($key === null || $key === '') {
            throw new InvalidArgumentException('OtpKit needs a saved model: ['.$model::class.'] has no primary key yet.');
        }

        return new self('model:'.$model->getMorphClass().':'.$key, model: $model);
    }

    /**
     * The address is hashed into the key, so stores never see raw addresses.
     */
    public static function fromDestination(string $channel, string $address): self
    {
        if (trim($address) === '') {
            throw new InvalidArgumentException('OtpKit needs a non-empty address.');
        }

        return new self(
            hash('sha256', $channel.':'.Hasher::normalizeAddress($address)),
            channel: $channel,
            address: trim($address),
        );
    }

    /**
     * Accepts a model, a Subject, or a mail address.
     */
    public static function from(Model|self|string $subject): self
    {
        return match (true) {
            $subject instanceof self => $subject,
            $subject instanceof Model => self::fromModel($subject),
            default => self::fromDestination('mail', $subject),
        };
    }

    public function isModel(): bool
    {
        return $this->model !== null;
    }
}
