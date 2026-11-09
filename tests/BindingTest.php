<?php

use Illuminate\Http\Request;
use TecClub\OtpKit\Enums\OtpStatus;
use TecClub\OtpKit\Tests\Fixtures\User;

function requestFrom(string $ip = '127.0.0.1', string $userAgent = 'Browser A'): void
{
    app()->instance('request', Request::create('/', 'POST', server: ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => $userAgent]));
}

it('has every binding off by default', function () {
    $user = User::make();
    requestFrom('10.0.0.1', 'Browser A');
    $code = $user->otp('verify-email')->sendTo('mail', 'new@example.com')->generate()->code;

    requestFrom('10.0.0.2', 'Browser B');

    expect(config('otpkit.defaults.bind_to'))->toBe([])
        ->and($user->otp('verify-email')->verify($code, destination: 'someone-else@example.com')->ok())->toBeTrue();
});

it('binds the IP when enabled globally', function () {
    config()->set('otpkit.defaults.bind_to', ['ip']);
    $user = User::make();
    requestFrom('10.0.0.1');
    $code = $user->otp()->generate()->code;

    requestFrom('10.0.0.2');
    expect($user->otp()->verify($code)->status)->toBe(OtpStatus::ContextMismatch);

    requestFrom('10.0.0.1');
    expect($user->otp()->verify($code)->ok())->toBeTrue();
});

it('binds the user agent when enabled per purpose', function () {
    config()->set('otpkit.purposes.confirm-transfer', ['bind_to' => ['user_agent']]);
    $user = User::make();
    requestFrom(userAgent: 'Browser A');
    $transfer = $user->otp('confirm-transfer')->generate()->code;
    $other = $user->otp('data-export')->generate()->code;

    requestFrom(userAgent: 'Browser B');

    expect($user->otp('confirm-transfer')->verify($transfer)->status)->toBe(OtpStatus::ContextMismatch)
        ->and($user->otp('data-export')->verify($other)->ok())->toBeTrue();

    requestFrom(userAgent: 'Browser A');
    expect($user->otp('confirm-transfer')->verify($transfer)->ok())->toBeTrue();
});

it('binds the destination when enabled globally', function () {
    config()->set('otpkit.defaults.bind_to', ['destination']);
    $user = User::make();
    $code = $user->otp('verify-email')->sendTo('mail', 'new@example.com')->generate()->code;

    expect($user->otp('verify-email')->verify($code, destination: 'other@example.com')->status)->toBe(OtpStatus::ContextMismatch)
        ->and($user->otp('verify-email')->verify($code)->status)->toBe(OtpStatus::ContextMismatch)
        ->and($user->otp('verify-email')->verify($code, destination: 'new@example.com')->ok())->toBeTrue();
});

it('combines bindings and counts a mismatch as an attempt', function () {
    config()->set('otpkit.purposes.confirm-transfer', ['bind_to' => ['ip', 'user_agent'], 'max_attempts' => 2]);
    $user = User::make();
    requestFrom('10.0.0.1', 'Browser A');
    $code = $user->otp('confirm-transfer')->generate()->code;

    requestFrom('10.0.0.1', 'Browser B');
    $mismatch = $user->otp('confirm-transfer')->verify($code);

    expect($mismatch->status)->toBe(OtpStatus::ContextMismatch)
        ->and($mismatch->attemptsRemaining)->toBe(1);

    requestFrom('10.0.0.9', 'Browser A');
    expect($user->otp('confirm-transfer')->verify($code)->status)->toBe(OtpStatus::TooManyAttempts);
});

it('stores the IP and user agent only as hashes', function () {
    config()->set('otpkit.defaults.bind_to', ['ip', 'user_agent']);
    requestFrom('10.0.0.1', 'Browser A');
    $user = User::make();
    $user->otp()->generate();

    $context = otpRecord($user)->context;

    expect($context['bind'])->toBe(['ip', 'user_agent'])
        ->and(json_encode($context))->not->toContain('10.0.0.1')->not->toContain('Browser A')
        ->and($context['ip'])->toHaveLength(64);
});

it('snapshots bindings onto the code when it is issued', function () {
    $user = User::make();
    requestFrom('10.0.0.1');
    $code = $user->otp()->generate()->code;

    config()->set('otpkit.defaults.bind_to', ['ip']);
    requestFrom('10.0.0.2');

    expect($user->otp()->verify($code)->ok())->toBeTrue();
});
