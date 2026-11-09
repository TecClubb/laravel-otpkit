<?php

namespace TecClub\OtpKit\Exceptions;

use InvalidArgumentException;

class InvalidConfigException extends InvalidArgumentException
{
    public static function invalid(string $key, string $problem, ?string $purpose = null): self
    {
        $where = $purpose === null ? '' : " (purpose [{$purpose}])";

        return new self("OtpKit config [{$key}]{$where} {$problem}.");
    }
}
