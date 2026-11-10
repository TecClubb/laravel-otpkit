<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use TecClub\OtpKit\Enums\OtpStatus;
use TecClub\OtpKit\Enums\SendStatus;
use TecClub\OtpKit\Events\OtpRateLimited;
use TecClub\OtpKit\Facades\Otp;
use TecClub\OtpKit\PolicyResolver;
use TecClub\OtpKit\Subject;
use TecClub\OtpKit\Support\Limiter;
use TecClub\OtpKit\Tests\Fixtures\User;

/*
 * Rate limits live in the OTP cache store (the array store here, so time travel
 * works). Time is frozen so retryAfter values are exact.
 */

beforeEach(fn () => $this->freezeTime());

function fromIp(string $ip): void
{
    app()->instance('request', Request::create('/', 'POST', server: ['REMOTE_ADDR' => $ip]));
}

it('escalates the resend cooldown 30, 60, 120, then 300 seconds', function () {
    config()->set('otpkit.rate_limits.send.per_subject_per_hour', null);
    $user = User::make();

    expect($user->otp()->send()->sent())->toBeTrue();

    foreach ([30, 60, 120, 300, 300] as $cooldown) {
        $blocked = $user->otp()->send();

        expect($blocked->status)->toBe(SendStatus::RateLimited)
            ->and($blocked->retryAfter)->toBe($cooldown);

        $this->travel($cooldown - 1)->seconds();
        expect($user->otp()->send()->retryAfter)->toBe(1);

        $this->travel(1)->seconds();
        expect($user->otp()->send()->sent())->toBeTrue();
    }
});

it('uses a fixed cooldown when the config is an integer', function () {
    config()->set('otpkit.rate_limits.send.cooldown', 45);
    config()->set('otpkit.rate_limits.send.per_subject_per_hour', null);
    $user = User::make();

    foreach (range(1, 3) as $send) {
        expect($user->otp()->send()->sent())->toBeTrue()
            ->and($user->otp()->send()->retryAfter)->toBe(45);

        $this->travel(45)->seconds();
    }
});

it('resets the cooldown after a successful verify', function () {
    $user = User::make();
    $user->otp()->send();
    $user->otp()->send(); // blocked

    $this->travel(30)->seconds();
    $user->otp()->send();
    expect($user->otp()->send()->retryAfter)->toBe(60);

    $code = $this->codeFromMail($this->sentMails()->last());
    expect($user->otp()->verify($code)->ok())->toBeTrue();

    // Back to the first step: a send right away, then 30 seconds.
    expect($user->otp()->send()->sent())->toBeTrue()
        ->and($user->otp()->send()->retryAfter)->toBe(30);
});

it('does not reset the cooldown on check()', function () {
    $user = User::make();
    $user->otp()->send();
    $code = $this->codeFromMail($this->sentMails()->last());

    expect($user->otp()->check($code)->ok())->toBeTrue()
        ->and($user->otp()->send()->status)->toBe(SendStatus::RateLimited);
});

it('keeps cooldowns separate per purpose', function () {
    $user = User::make();
    $user->otp('data-export')->send();

    expect($user->otp('delete-account')->send()->sent())->toBeTrue()
        ->and($user->otp('data-export')->send()->sent())->toBeFalse();
});

it('limits sends per subject per hour', function () {
    config()->set('otpkit.rate_limits.send.cooldown', null);
    $user = User::make();

    foreach (range(1, 5) as $send) {
        expect($user->otp()->send()->sent())->toBeTrue();
    }

    $blocked = $user->otp()->send();

    expect($blocked->status)->toBe(SendStatus::RateLimited)
        ->and($blocked->retryAfter)->toBe(3600);

    $this->travel(3600)->seconds();

    expect($user->otp()->send()->sent())->toBeTrue();
});

it('limits sends per IP per hour across subjects', function () {
    config()->set('otpkit.rate_limits.send.per_ip_per_hour', 3);
    fromIp('203.0.113.7');

    foreach (range(1, 3) as $i) {
        expect(Otp::to('mail', "guest{$i}@example.com")->send()->sent())->toBeTrue();
    }

    $blocked = Otp::to('mail', 'guest4@example.com')->send();

    expect($blocked->status)->toBe(SendStatus::RateLimited)
        ->and($blocked->retryAfter)->toBe(3600);

    fromIp('203.0.113.8');
    expect(Otp::to('mail', 'guest4@example.com')->send()->sent())->toBeTrue();
});

it('limits verifies per IP per minute across subjects', function () {
    config()->set('otpkit.rate_limits.verify.per_ip_per_minute', 3);
    $users = collect(range(1, 4))->map(fn ($i) => User::make("u{$i}@example.com"));
    $codes = $users->map(fn ($user) => $user->otp()->generate()->code);

    foreach ([0, 1, 2] as $i) {
        $users[$i]->otp()->verify(wrongCode($codes[$i]));
    }

    $limited = $users[3]->otp()->verify($codes[3]);

    expect($limited->status)->toBe(OtpStatus::RateLimited)
        ->and($limited->retryAfter)->toBe(60);

    $this->travel(60)->seconds();

    expect($users[3]->otp()->verify($codes[3])->ok())->toBeTrue();
});

it('creates no code and sends nothing when rate limited', function () {
    $user = User::make();
    $first = $user->otp()->send();

    $blocked = $user->otp()->send();

    expect($blocked->sent())->toBeFalse()
        ->and($blocked->id)->toBeNull()
        ->and($blocked->expiresAt)->toBeNull()
        ->and($this->sentMails())->toHaveCount(1)
        ->and(otpRecord($user)->id)->toBe($first->id);
});

it('fires OtpRateLimited with the limit name and IP', function () {
    Event::fake([OtpRateLimited::class]);
    fromIp('198.51.100.4');
    $user = User::make();

    $user->otp('data-export')->send();
    $user->otp('data-export')->send();

    Event::assertDispatched(OtpRateLimited::class, fn ($event) => $event->limit === 'send.cooldown'
        && $event->purpose === 'data-export'
        && $event->ip === '198.51.100.4'
        && $event->subjectKey === Subject::fromModel($user)->key);
});

it('disables any single limit with null', function () {
    config()->set('otpkit.rate_limits.send', ['cooldown' => null, 'per_subject_per_hour' => null, 'per_ip_per_hour' => null]);
    $user = User::make();

    foreach (range(1, 25) as $send) {
        expect($user->otp()->send()->sent())->toBeTrue();
    }
});

it('overrides limits per purpose', function () {
    config()->set('otpkit.purposes.login', ['rate_limits' => ['send' => ['cooldown' => [5]]]]);
    $user = User::make();

    $user->otp('login')->send();
    expect($user->otp('login')->send()->retryAfter)->toBe(5);

    $user->otp('data-export')->send();
    expect($user->otp('data-export')->send()->retryAfter)->toBe(30);
});

it('skips IP limits when there is no IP, as in queues and the console', function () {
    config()->set('otpkit.rate_limits.send.per_ip_per_hour', 1);
    config()->set('otpkit.rate_limits.verify.per_ip_per_minute', 1);
    $policy = app(PolicyResolver::class)->resolve('default');
    $limiter = app(Limiter::class);

    foreach (range(1, 3) as $i) {
        expect($limiter->checkSend($policy, "subject-{$i}", null))->toBeNull();
        $limiter->hitSend($policy, "subject-{$i}", null);

        expect($limiter->hitVerify($policy, null))->toBeNull();
    }

    // Subject limits still apply without an IP.
    expect($limiter->checkSend($policy, 'subject-1', null)['limit'])->toBe('send.cooldown');
});
