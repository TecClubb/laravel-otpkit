<?php

namespace TecClub\OtpKit\Generators;

use TecClub\OtpKit\Contracts\CodeGenerator;

class NumericGenerator implements CodeGenerator
{
    public function generate(int $length): string
    {
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }
}
