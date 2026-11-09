<?php

namespace TecClub\OtpKit\Generators;

use TecClub\OtpKit\Contracts\CodeGenerator;

class AlphanumericGenerator implements CodeGenerator
{
    /**
     * Uppercase letters and digits without 0, O, 1, I and L, which people misread.
     */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function generate(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
