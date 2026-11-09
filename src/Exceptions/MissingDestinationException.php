<?php

namespace TecClub\OtpKit\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class MissingDestinationException extends LogicException
{
    public static function forModel(Model $model): self
    {
        return new self(
            'OtpKit cannot deliver a code to ['.$model::class.']: the model does not use the Notifiable trait '
            .'and no destination was given. Add Notifiable to the model or call sendTo() first.'
        );
    }
}
