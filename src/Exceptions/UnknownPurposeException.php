<?php

namespace TecClub\OtpKit\Exceptions;

use InvalidArgumentException;

class UnknownPurposeException extends InvalidArgumentException
{
    public static function named(string $purpose): self
    {
        return new self("OtpKit purpose [{$purpose}] is not listed in otpkit.purposes, and strict_purposes is on.");
    }
}
