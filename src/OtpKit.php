<?php

namespace TecClub\OtpKit;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Route;
use TecClub\OtpKit\Contracts\CodeGenerator;
use TecClub\OtpKit\Contracts\OtpStore;
use TecClub\OtpKit\Generators\GeneratorRegistry;
use TecClub\OtpKit\Login\Http\Controllers\ApiVerifyController;
use TecClub\OtpKit\Login\Http\Controllers\SendLoginCodeController;
use TecClub\OtpKit\Login\Http\Controllers\WebVerifyController;
use TecClub\OtpKit\Login\LoginService;
use TecClub\OtpKit\Login\SanctumTokenIssuer;
use TecClub\OtpKit\Stores\StoreManager;

/**
 * Package-level extension points and login route registration.
 */
class OtpKit
{
    /**
     * Register a custom store driver, selected with otpkit.store = $name.
     *
     * @param  Closure(Container): OtpStore  $callback
     */
    public static function extend(string $name, Closure $callback): void
    {
        app(StoreManager::class)->extend($name, $callback);
    }

    /**
     * Register a custom code format, used as charset = $name.
     *
     * @param  class-string<CodeGenerator>|Closure(): CodeGenerator  $generator
     */
    public static function generator(string $name, string|Closure $generator): void
    {
        app(GeneratorRegistry::class)->register($name, $generator);
    }

    /**
     * Block logins by code for some accounts (banned, unverified, suspended).
     * A blocked account gets the same generic failure as a wrong code.
     *
     * @param  (Closure(Authenticatable): bool)|null  $callback  return false to block
     */
    public static function authorizeLoginUsing(?Closure $callback): void
    {
        app(LoginService::class)->authorizeUsing($callback);
    }

    /**
     * POST {prefix}/send and POST {prefix}/verify for session login. Registers
     * nothing while otpkit.login.enabled is false.
     *
     * @param  list<string>|null  $guards  login guards these routes accept; null = all configured, the first is the default
     * @param  list<string>  $middleware
     */
    public static function webLoginRoutes(string $prefix = 'otp-login', ?array $guards = null, array $middleware = ['web']): void
    {
        if (! config('otpkit.login.enabled', false)) {
            return;
        }

        Route::middleware($middleware)->prefix($prefix)->name('otpkit.login.')->group(function () use ($guards) {
            Route::post('send', SendLoginCodeController::class)->name('send')->defaults('otpkit_guards', $guards);
            Route::post('verify', WebVerifyController::class)->name('verify')->defaults('otpkit_guards', $guards);
        });
    }

    /**
     * POST {prefix}/send and POST {prefix}/verify for token login. Registers
     * nothing while otpkit.login.enabled is false. With the default Sanctum
     * issuer, throws now if Sanctum is missing or a guard's model lacks HasApiTokens.
     *
     * @param  list<string>|null  $guards  login guards these routes accept; null = all configured, the first is the default
     * @param  list<string>  $middleware
     */
    public static function apiLoginRoutes(string $prefix = 'api/otp-login', ?array $guards = null, array $middleware = ['api']): void
    {
        if (! config('otpkit.login.enabled', false)) {
            return;
        }

        $login = app(LoginService::class);

        if ($login->tokenIssuer() instanceof SanctumTokenIssuer) {
            foreach ($guards ?? array_keys(config('otpkit.login.guards', [])) as $guard) {
                SanctumTokenIssuer::assertUsableFor($login->guardConfig($guard)['model']);
            }
        }

        Route::middleware($middleware)->prefix($prefix)->name('otpkit.login.api.')->group(function () use ($guards) {
            Route::post('send', SendLoginCodeController::class)->name('send')->defaults('otpkit_guards', $guards);
            Route::post('verify', ApiVerifyController::class)->name('verify')->defaults('otpkit_guards', $guards);
        });
    }
}
