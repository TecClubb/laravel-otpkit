<?php

use Illuminate\Support\Facades\Event;
use TecClub\OtpKit\Enums\OtpStatus;
use TecClub\OtpKit\Events\OtpFailed;
use TecClub\OtpKit\Events\OtpGenerated;
use TecClub\OtpKit\Events\OtpLocked;
use TecClub\OtpKit\Events\OtpRateLimited;
use TecClub\OtpKit\Events\OtpSent;
use TecClub\OtpKit\Events\OtpVerified;
use TecClub\OtpKit\Subject;
use TecClub\OtpKit\Tests\Fixtures\User;

it('fires all six events with the documented payloads and never the code', function () {
    Event::fake([OtpGenerated::class, OtpSent::class, OtpVerified::class, OtpFailed::class, OtpLocked::class, OtpRateLimited::class]);
    config()->set('otpkit.purposes.locked', ['max_attempts' => 1]);
    // Long codes, so the "never the code" check can't match by chance.
    config()->set('otpkit.defaults.charset', 'alphanumeric');
    config()->set('otpkit.defaults.length', 12);
    $user = User::make();
    $subjectKey = Subject::fromModel($user)->key;

    $sent = $user->otp('data-export')->send();
    $code = $this->codeFromMail($this->sentMails()->sole());

    $user->otp('data-export')->send();                       // OtpRateLimited (cooldown)
    $user->otp('data-export')->verify(wrongCode($code));      // OtpFailed (Invalid)
    $user->otp('data-export')->verify($code);                 // OtpVerified

    $locked = $user->otp('locked')->generate();
    $user->otp('locked')->verify(wrongCode($locked->code));   // OtpLocked + OtpFailed (TooManyAttempts)

    Event::assertDispatched(OtpGenerated::class, fn (OtpGenerated $e) => $e->subjectKey === $subjectKey
        && $e->purpose === 'data-export' && $e->id === $sent->id && $e->expiresAt->equalTo($sent->expiresAt));

    Event::assertDispatched(OtpSent::class, fn (OtpSent $e) => $e->subjectKey === $subjectKey
        && $e->purpose === 'data-export' && $e->id === $sent->id);

    Event::assertDispatched(OtpRateLimited::class, fn (OtpRateLimited $e) => $e->subjectKey === $subjectKey
        && $e->purpose === 'data-export' && $e->limit === 'send.cooldown' && $e->ip === '127.0.0.1');

    Event::assertDispatched(OtpFailed::class, fn (OtpFailed $e) => $e->subjectKey === $subjectKey
        && $e->purpose === 'data-export' && $e->status === OtpStatus::Invalid);

    Event::assertDispatched(OtpVerified::class, fn (OtpVerified $e) => $e->subjectKey === $subjectKey
        && $e->purpose === 'data-export' && $e->id === $sent->id);

    Event::assertDispatched(OtpLocked::class, fn (OtpLocked $e) => $e->subjectKey === $subjectKey
        && $e->purpose === 'locked' && $e->id === $locked->id);

    Event::assertDispatched(OtpFailed::class, fn (OtpFailed $e) => $e->purpose === 'locked' && $e->status === OtpStatus::TooManyAttempts);

    foreach ([OtpGenerated::class, OtpSent::class, OtpVerified::class, OtpFailed::class, OtpLocked::class, OtpRateLimited::class] as $event) {
        foreach (Event::dispatched($event) as [$dispatched]) {
            expect(serialize($dispatched))->not->toContain($code)->not->toContain($locked->code);
        }
    }
});

it('fires OtpRateLimited for the per-IP verify limit', function () {
    Event::fake([OtpRateLimited::class]);
    config()->set('otpkit.rate_limits.verify.per_ip_per_minute', 1);
    $user = User::make();
    $code = $user->otp()->generate()->code;

    $user->otp()->verify(wrongCode($code));
    $user->otp()->verify($code);

    Event::assertDispatched(OtpRateLimited::class, fn (OtpRateLimited $e) => $e->limit === 'verify.per_ip_per_minute' && $e->ip === '127.0.0.1');
});

it('does not fire OtpVerified for a successful check()', function () {
    Event::fake([OtpVerified::class]);
    $user = User::make();
    $code = $user->otp()->generate()->code;

    $user->otp()->check($code);

    Event::assertNotDispatched(OtpVerified::class);
});
