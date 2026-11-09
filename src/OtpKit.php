<?php

namespace TecClub\OtpKit;

use Closure;
use Illuminate\Contracts\Container\Container;
use TecClub\OtpKit\Contracts\CodeGenerator;
use TecClub\OtpKit\Contracts\OtpStore;
use TecClub\OtpKit\Generators\GeneratorRegistry;
use TecClub\OtpKit\Stores\StoreManager;

/**
 * Package-level extension points.
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
}
