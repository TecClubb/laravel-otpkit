<?php

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use TecClub\OtpKit\Facades\Otp;
use TecClub\OtpKit\Tests\Fixtures\User;

it('records codes without a real store or mail', function () {
    Otp::fake();
    $user = User::make();

    $user->otp('data-export')->send();

    Otp::assertSent($user, 'data-export');
    Otp::assertNotSent($user, 'delete-account');

    expect($this->sentMails())->toBeEmpty()
        ->and(DB::table('one_time_passwords')->count())->toBe(0)
        ->and(Otp::lastCode($user, 'data-export'))->toMatch('/^\d{6}$/');
});

it('verifies recorded codes', function () {
    Otp::fake();
    $user = User::make();
    $user->otp('data-export')->send();

    expect($user->otp('data-export')->verify(Otp::lastCode($user, 'data-export'))->ok())->toBeTrue();

    Otp::assertVerified($user, 'data-export');
    Otp::assertNotVerified($user, 'delete-account');
});

it('uses a fixed code for end-to-end tests', function () {
    Otp::fake(code: '123456');
    $user = User::make();

    $user->otp()->send();

    expect(Otp::lastCode($user))->toBe('123456')
        ->and($user->otp()->verify('123456')->ok())->toBeTrue();
});

it('works for address subjects', function () {
    Otp::fake();

    Otp::to('mail', 'guest@example.com')->purpose('guest-checkout')->send();

    Otp::assertSent('guest@example.com', 'guest-checkout', times: 1);
    expect(Otp::lastCode('guest@example.com', 'guest-checkout'))->not->toBeNull();
});

it('fails assertions when nothing matches', function () {
    Otp::fake();

    expect(fn () => Otp::assertSent(User::make(), 'data-export'))->toThrow(AssertionFailedError::class)
        ->and(fn () => Otp::assertVerified(User::make('b@example.com')))->toThrow(AssertionFailedError::class);
});

it('still runs the real verification rules', function () {
    Otp::fake(code: '123456');
    $user = User::make();
    $user->otp()->maxAttempts(2)->send();

    $user->otp()->verify('000000');

    expect($user->otp()->verify('000000')->status->value)->toBe('too_many_attempts');
    Otp::assertNotVerified($user);
});
