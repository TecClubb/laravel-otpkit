<?php

namespace TecClub\OtpKit\Facades;

use Illuminate\Support\Facades\Facade;
use TecClub\OtpKit\OtpManager;
use TecClub\OtpKit\Testing\OtpFake;

/**
 * @method static \TecClub\OtpKit\PendingOtp for(\Illuminate\Database\Eloquent\Model $model)
 * @method static \TecClub\OtpKit\PendingOtp to(string $channel, string $address)
 * @method static bool isCurrent(string $id)
 * @method static \TecClub\OtpKit\Contracts\OtpStore store()
 * @method static void assertSent(\Illuminate\Database\Eloquent\Model|\TecClub\OtpKit\Subject|string $subject, string $purpose = 'default', ?int $times = null)
 * @method static void assertNotSent(\Illuminate\Database\Eloquent\Model|\TecClub\OtpKit\Subject|string $subject, string $purpose = 'default')
 * @method static void assertVerified(\Illuminate\Database\Eloquent\Model|\TecClub\OtpKit\Subject|string $subject, string $purpose = 'default')
 * @method static void assertNotVerified(\Illuminate\Database\Eloquent\Model|\TecClub\OtpKit\Subject|string $subject, string $purpose = 'default')
 * @method static string|null lastCode(\Illuminate\Database\Eloquent\Model|\TecClub\OtpKit\Subject|string $subject, string $purpose = 'default')
 *
 * @see OtpManager
 * @see OtpFake
 */
class Otp extends Facade
{
    /**
     * Replace the real store and delivery with an in-memory fake that records every code.
     *
     * @param  string|null  $code  a fixed code every issue returns, for end-to-end tests
     */
    public static function fake(?string $code = null): OtpFake
    {
        $fake = OtpFake::make(static::getFacadeApplication(), $code);

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return OtpManager::class;
    }
}
