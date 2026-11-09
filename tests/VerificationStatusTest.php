<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use TecClub\OtpKit\Enums\OtpStatus;
use TecClub\OtpKit\Events\OtpFailed;
use TecClub\OtpKit\Events\OtpLocked;
use TecClub\OtpKit\Events\OtpRateLimited;
use TecClub\OtpKit\Events\OtpVerified;
use TecClub\OtpKit\Tests\Fixtures\User;

function wrongCode(string $code): string
{
    return $code === '000000' ? '111111' : '000000';
}

it('returns Verified for the right code and consumes it', function () {
    $user = User::make();
    $code = $user->otp()->generate()->code;

    $result = $user->otp()->verify($code);

    expect($result->status)->toBe(OtpStatus::Verified)
        ->and($result->ok())->toBeTrue()
        ->and($result->message())->toBeNull()
        ->and($user->oneTimePasswords()->first()->consumed_at)->not->toBeNull();
});

it('returns Invalid for a wrong code with attempts left', function () {
    $user = User::make();
    $code = $user->otp()->generate()->code;

    $result = $user->otp()->verify(wrongCode($code));

    expect($result->status)->toBe(OtpStatus::Invalid)
        ->and($result->attemptsRemaining)->toBe(4)
        ->and($result->message())->toBe('The code is invalid or has expired.')
        ->and($user->otp()->pending())->toBeTrue();
});

it('returns Expired past expires_at', function () {
    $user = User::make();
    $code = $user->otp()->generate()->code;

    $this->travel(121)->seconds();

    expect($user->otp()->verify($code)->status)->toBe(OtpStatus::Expired);
});

it('returns NotFound when no code exists', function () {
    expect(User::make()->otp()->verify('123456')->status)->toBe(OtpStatus::NotFound);
});

it('returns AlreadyUsed for a consumed code', function () {
    $user = User::make();
    $code = $user->otp()->generate()->code;
    $user->otp()->verify($code);

    expect($user->otp()->verify($code)->status)->toBe(OtpStatus::AlreadyUsed);
});

it('returns ContextMismatch when the destination binding fails', function () {
    config()->set('otpkit.purposes.verify-email', ['bind_to' => ['destination']]);
    $user = User::make();
    $code = $user->otp('verify-email')->sendTo('mail', 'new@example.com')->generate()->code;

    $mismatch = $user->otp('verify-email')->verify($code, destination: 'other@example.com');

    expect($mismatch->status)->toBe(OtpStatus::ContextMismatch)
        ->and($mismatch->attemptsRemaining)->toBe(4)
        ->and($user->otp('verify-email')->verify($code)->status)->toBe(OtpStatus::ContextMismatch)
        ->and($user->otp('verify-email')->verify($code, destination: ' NEW@example.com ')->ok())->toBeTrue();
});

it('ignores the destination argument when no binding is enabled', function () {
    $user = User::make();
    $code = $user->otp('verify-email')->sendTo('mail', 'new@example.com')->generate()->code;

    expect($user->otp('verify-email')->verify($code, destination: 'other@example.com')->ok())->toBeTrue();
});

it('returns TooManyAttempts when the attempt limit is reached, and burns the code', function () {
    Event::fake([OtpLocked::class, OtpFailed::class]);
    $user = User::make();
    $code = $user->otp()->generate()->code;

    foreach (range(1, 4) as $attempt) {
        expect($user->otp()->verify(wrongCode($code))->status)->toBe(OtpStatus::Invalid);
    }

    $last = $user->otp()->verify(wrongCode($code));

    expect($last->status)->toBe(OtpStatus::TooManyAttempts)
        ->and($last->attemptsRemaining)->toBe(0)
        ->and($last->message())->toBe('Too many attempts. Request a new code.')
        ->and($user->otp()->verify($code)->status)->toBe(OtpStatus::TooManyAttempts)
        ->and($user->otp()->pending())->toBeFalse();

    Event::assertDispatchedTimes(OtpLocked::class, 1);
});

it('returns RateLimited past the per-IP verify limit, leaving the code unchanged', function () {
    Event::fake([OtpRateLimited::class]);
    config()->set('otpkit.rate_limits.verify.per_ip_per_minute', 2);
    $user = User::make();
    $code = $user->otp()->generate()->code;

    $user->otp()->verify(wrongCode($code));
    $user->otp()->verify(wrongCode($code));
    $limited = $user->otp()->verify($code);

    expect($limited->status)->toBe(OtpStatus::RateLimited)
        ->and($limited->retryAfter)->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($limited->message())->toBe("Too many attempts. Try again in {$limited->retryAfter} seconds.")
        ->and($user->oneTimePasswords()->first()->attempts)->toBe(2);

    Event::assertDispatched(OtpRateLimited::class, fn ($event) => $event->limit === 'verify.per_ip_per_minute' && $event->ip === '127.0.0.1');

    $this->travel(61)->seconds();

    expect($user->otp()->verify($code)->ok())->toBeTrue();
});

it('can disable the per-IP verify limit with null', function () {
    config()->set('otpkit.rate_limits.verify.per_ip_per_minute', null);
    $user = User::make();
    $code = $user->otp()->maxAttempts(50)->generate()->code;

    foreach (range(1, 15) as $attempt) {
        $user->otp()->check(wrongCode($code));
    }

    expect($user->otp()->verify($code)->ok())->toBeTrue();
});

it('collapses messages unless verbose is on', function () {
    $user = User::make();
    $code = $user->otp()->generate()->code;
    $user->otp()->verify($code);

    $statuses = [
        $user->otp()->verify($code),                // AlreadyUsed
        User::make('x@example.com')->otp()->verify('1'), // NotFound
    ];

    foreach ($statuses as $result) {
        expect($result->message())->toBe('The code is invalid or has expired.');
    }

    config()->set('otpkit.messages.verbose', true);

    expect($statuses[0]->message())->toBe('The code has already been used.')
        ->and($statuses[1]->message())->toBe('No code was requested, or it has been replaced by a newer one.');
});

it('throws a ValidationException on the given field', function () {
    $result = User::make()->otp()->verify('123456');

    try {
        $result->throw('otp');
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['otp' => ['The code is invalid or has expired.']]);
    }
});

it('fires OtpVerified and OtpFailed', function () {
    Event::fake([OtpVerified::class, OtpFailed::class]);
    $user = User::make();
    $issued = $user->otp('data-export')->generate();

    $user->otp('data-export')->verify(wrongCode($issued->code));
    $user->otp('data-export')->verify($issued->code);

    Event::assertDispatched(OtpFailed::class, fn ($event) => $event->status === OtpStatus::Invalid && $event->purpose === 'data-export');
    Event::assertDispatched(OtpVerified::class, fn ($event) => $event->id === $issued->id);
});
