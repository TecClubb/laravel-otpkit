<?php

namespace TecClub\OtpKit\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Issues an API token after a successful login by code. The default uses
 * Sanctum; bind your own (Passport, JWT) in the container or set
 * otpkit.login.token_issuer.
 */
interface TokenIssuer
{
    /**
     * Return the plaintext token handed to the client.
     *
     * @param  string  $name  the device name from the request, or otpkit.login.token_name
     */
    public function issue(Authenticatable $user, string $name): string;

    /**
     * The token type returned alongside the token, usually "Bearer".
     */
    public function tokenType(): string;
}
