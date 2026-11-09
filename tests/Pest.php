<?php

use Illuminate\Database\Eloquent\Model;
use TecClub\OtpKit\Facades\Otp;
use TecClub\OtpKit\Stores\OtpRecord;
use TecClub\OtpKit\Subject;
use TecClub\OtpKit\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Stores every shared suite runs against. Redis is skipped unless OTPKIT_TEST_REDIS_HOST is set.
 *
 * @return list<string>
 */
function otpStores(): array
{
    return ['database', 'cache', 'redis'];
}

/**
 * The stored record for a subject and purpose, from whichever store is active.
 */
function otpRecord(Model|string $subject, string $purpose = 'default'): ?OtpRecord
{
    return Otp::store()->find(Subject::from($subject)->key, $purpose);
}

/**
 * A code guaranteed to differ from the given one.
 */
function wrongCode(string $code): string
{
    return $code === '000000' ? '111111' : '000000';
}
