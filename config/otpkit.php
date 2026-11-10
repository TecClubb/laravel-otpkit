<?php

use TecClub\OtpKit\Login\SanctumTokenIssuer;
use TecClub\OtpKit\Notifications\OneTimePasswordNotification;

return [

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    |
    | Where hashed codes live: "database" (default), "cache", or the name of a
    | custom driver registered with OtpKit::extend(). When using "cache",
    | "cache_store" names a store from config/cache.php (null = app default).
    |
    */

    'store' => env('OTPKIT_STORE', 'database'),

    'cache_store' => env('OTPKIT_CACHE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Default policy
    |--------------------------------------------------------------------------
    |
    | The rules every code follows unless its purpose or the call overrides
    | them. charset: "numeric", "alphanumeric" or a registered generator.
    | ttl is in seconds. bind_to may hold "destination", "ip", "user_agent".
    |
    */

    'defaults' => [
        'length' => 6,
        'charset' => 'numeric',
        'ttl' => 120,
        'max_attempts' => 5,
        'bind_to' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Purposes
    |--------------------------------------------------------------------------
    |
    | Optional. List only purposes that differ from the defaults. Each entry
    | may override: length, charset, ttl, max_attempts, bind_to, rate_limits
    | and notification.
    |
    */

    'purposes' => [
        // 'delete-account' => ['length' => 8, 'max_attempts' => 3],
        // 'verify-email' => ['bind_to' => ['destination']],
    ],

    // true = purposes missing from the list above throw UnknownPurposeException.
    'strict_purposes' => false,

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    */

    'notification' => OneTimePasswordNotification::class,

    'queue' => [
        'enabled' => true,                                // false = notifyNow()
        'connection' => env('OTPKIT_QUEUE_CONNECTION'),   // null = app default
        'name' => env('OTPKIT_QUEUE'),                    // null = default queue
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Set any single limit to null to disable it. cooldown is the number of
    | seconds before each resend; a list escalates, an int is fixed.
    |
    */

    'rate_limits' => [
        'send' => [
            'cooldown' => [30, 60, 120, 300],
            'per_subject_per_hour' => 5,
            'per_ip_per_hour' => 20,
        ],
        'verify' => [
            'per_ip_per_minute' => 10,
        ],
    ],

    'messages' => [
        // true = a distinct message per status instead of the collapsed ones.
        'verbose' => false,
    ],

    'database' => [
        'connection' => null,
        'table' => 'one_time_passwords',
    ],

    'cache' => [
        'prefix' => 'otpkit',
        'grace' => 60, // seconds kept after expiry so verify can report Expired
    ],

    'prune' => [
        'schedule' => true,    // auto-register otpkit:prune daily; false = schedule it yourself
        'keep_for_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Login by code
    |--------------------------------------------------------------------------
    |
    | Off by default. Routes are never registered automatically: call
    | OtpKit::webLoginRoutes() / OtpKit::apiLoginRoutes(), or use the OtpLogin
    | facade from your own code. Each guard names its model and exactly one
    | identifier column. Codes use the "login" purpose, so purposes.login and
    | every rate limit apply.
    |
    */

    'login' => [
        'enabled' => env('OTPKIT_LOGIN_ENABLED', false),
        'guards' => [
            'web' => [
                'model' => 'App\Models\User',
                'identifier' => 'email',
            ],
        ],
        'redirect_to' => '/',   // after web login, when there is no intended URL
        'token_issuer' => SanctumTokenIssuer::class,
        'token_name' => 'otp-login', // used when the request sends no device_name
    ],

];
