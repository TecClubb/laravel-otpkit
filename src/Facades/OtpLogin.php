<?php

namespace TecClub\OtpKit\Facades;

use Illuminate\Support\Facades\Facade;
use TecClub\OtpKit\Login\LoginService;

/**
 * @method static void send(string $identifier, ?string $guard = null)
 * @method static \TecClub\OtpKit\Login\LoginResult attempt(string $identifier, string $code, ?string $guard = null, bool $remember = false)
 * @method static \TecClub\OtpKit\Login\LoginResult attemptForToken(string $identifier, string $code, ?string $guard = null, ?string $deviceName = null)
 *
 * @see LoginService
 */
class OtpLogin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LoginService::class;
    }
}
