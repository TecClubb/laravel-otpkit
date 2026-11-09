<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use TecClub\OtpKit\Enums\OtpStatus;
use TecClub\OtpKit\Events\OtpGenerated;
use TecClub\OtpKit\Events\OtpSent;
use TecClub\OtpKit\Facades\Otp;
use TecClub\OtpKit\Subject;
use TecClub\OtpKit\Tests\Fixtures\User;

it('stores only an HMAC of the code, keyed by subject and purpose', function () {
    $user = User::make();
    $issued = $user->otp('data-export')->generate();

    $row = DB::table('one_time_passwords')->first();
    $key = base64_decode(substr(config('app.key'), 7));

    expect(json_encode($row))->not->toContain($issued->code)
        ->and($row->code_hash)->toBe(hash_hmac('sha256', "model:{$user->getMorphClass()}:{$user->id}|data-export|{$issued->code}", $key))
        ->and($row->subject_key)->toBe(Subject::fromModel($user)->key)
        ->and($row->id)->toBe($issued->id)
        ->and(strlen($row->id))->toBe(26);
});

it('never stores raw addresses', function () {
    Otp::to('mail', 'guest@example.com')->purpose('guest-checkout')->generate();
    User::make()->otp('verify-email')->sendTo('mail', 'new@example.com')->generate();

    $rows = json_encode(DB::table('one_time_passwords')->get());

    expect($rows)->not->toContain('guest@example.com')
        ->not->toContain('new@example.com')
        ->and(DB::table('one_time_passwords')->where('purpose', 'guest-checkout')->value('subject_key'))
        ->toBe(hash('sha256', 'mail:guest@example.com'));
});

it('keeps the plaintext code out of events and logs', function () {
    Log::spy();
    $events = [];
    Event::listen('TecClub\OtpKit\Events\*', function (string $name, array $payload) use (&$events) {
        $events[] = $payload[0];
    });

    $user = User::make();
    $user->otp()->send();
    $code = $this->codeFromMail($this->sentMails()->sole());
    $user->otp()->verify($code);

    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        expect(serialize($event))->not->toContain($code);
    }

    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('debug');
});

it('snapshots the policy onto the code when issued', function () {
    $user = User::make();
    $code = $user->otp()->generate()->code;

    config()->set('otpkit.defaults.max_attempts', 1);
    config()->set('otpkit.defaults.ttl', 1);

    $this->travel(30)->seconds();

    expect($user->otp()->verify($code === '000000' ? '111111' : '000000')->status)->toBe(OtpStatus::Invalid)
        ->and($user->otp()->verify($code)->ok())->toBeTrue();
});

it('fires OtpGenerated and OtpSent', function () {
    Event::fake([OtpGenerated::class, OtpSent::class]);
    $user = User::make();

    $send = $user->otp('data-export')->send();

    Event::assertDispatched(OtpGenerated::class, fn ($event) => $event->id === $send->id
        && $event->purpose === 'data-export'
        && $event->subjectKey === Subject::fromModel($user)->key
        && $event->expiresAt->equalTo($send->expiresAt));
    Event::assertDispatched(OtpSent::class, fn ($event) => $event->id === $send->id);
});
