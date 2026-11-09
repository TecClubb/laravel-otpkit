<?php

namespace TecClub\OtpKit\Contracts;

interface CodeGenerator
{
    /**
     * Generate a code of the given length using a CSPRNG (random_int).
     *
     * Codes are compared after trimming, uppercasing and removing spaces and
     * hyphens, so generators should only emit characters that survive that.
     */
    public function generate(int $length): string;
}
