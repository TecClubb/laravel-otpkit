<?php

use Illuminate\Support\Facades\Event;
use TecClub\OtpKit\Enums\OtpStatus;
use TecClub\OtpKit\Enums\SendStatus;
use TecClub\OtpKit\Events\OtpGenerated;
use TecClub\OtpKit\Events\OtpSent;
use TecClub\OtpKit\Exceptions\MissingDestinationException;
use TecClub\OtpKit\Facades\Otp;
use TecClub\OtpKit\Tests\Fixtures\Device;
use TecClub\OtpKit\Tests\Fixtures\User;

foreach (otpStores() as $store) {
    describe("{$store} store", function () use ($store) {
        beforeEach(fn () => $this->useStore($store));

        it('sends a code to a notifiable model and verifies it', function () {
            // Frozen so expiresAt and the expectation below read the same second.
            $this->freezeTime();
            $user = User::make();

            $send = $user->otp('data-export')->send();

            expect($send->sent())->toBeTrue()
                ->and($send->status)->toBe(SendStatus::Sent)
                ->and($send->expiresAt->getTimestamp())->toBe(now()->addSeconds(120)->getTimestamp())
                ->and($this->sentMails())->toHaveCount(1);

            $mail = $this->sentMails()->first();
            expect($mail->getTo()[0]->getAddress())->toBe('jane@example.com');

            $result = $user->otp('data-export')->verify($this->codeFromMail($mail));

            expect($result->ok())->toBeTrue()
                ->and($result->status)->toBe(OtpStatus::Verified)
                ->and($user->otp('data-export')->pending())->toBeFalse();
        });

        it('uses the default purpose when none is given', function () {
            $user = User::make();
            $code = $user->otp()->generate()->code;

            expect($user->otp('default')->pending())->toBeTrue()
                ->and($user->otp()->verify($code)->ok())->toBeTrue();
        });

        it('never lets a code for one purpose work for another', function () {
            $user = User::make();
            $code = $user->otp('data-export')->generate()->code;

            expect($user->otp('delete-account')->verify($code)->status)->toBe(OtpStatus::NotFound)
                ->and($user->otp('data-export')->verify($code)->ok())->toBeTrue();
        });

        it('never lets a code for one subject work for another', function () {
            $jane = User::make();
            $john = User::make('john@example.com');
            $code = $jane->otp()->generate()->code;

            expect($john->otp()->verify($code)->status)->toBe(OtpStatus::NotFound);
        });

        it('sends codes to an address with no model', function () {
            $send = Otp::to('mail', 'Guest@Example.com')->purpose('guest-checkout')->send();

            expect($send->sent())->toBeTrue();

            $mail = $this->sentMails()->sole();
            $code = $this->codeFromMail($mail);

            expect($mail->getTo()[0]->getAddress())->toBe('Guest@Example.com')
                // Address subjects are case-insensitive.
                ->and(Otp::to('mail', 'guest@example.com')->purpose('guest-checkout')->verify($code)->ok())->toBeTrue();
        });

        it('delivers to another address with sendTo()', function () {
            $user = User::make();

            $user->otp('verify-email')->sendTo('mail', 'new@example.com')->send();

            $mail = $this->sentMails()->sole();

            expect($mail->getTo()[0]->getAddress())->toBe('new@example.com')
                ->and($user->otp('verify-email')->verify($this->codeFromMail($mail), destination: 'new@example.com')->ok())->toBeTrue();
        });

        it('throws a clear error for a model without Notifiable and no destination', function () {
            $device = Device::create(['name' => 'Phone']);

            expect(fn () => $device->otp('pair-device')->send())
                ->toThrow(MissingDestinationException::class, 'does not use the Notifiable trait')
                ->and($device->otp('pair-device')->pending())->toBeFalse();

            $device->otp('pair-device')->sendTo('mail', 'owner@example.com')->send();

            expect($this->sentMails())->toHaveCount(1);
        });

        it('generates without sending anything', function () {
            Event::fake([OtpGenerated::class, OtpSent::class]);
            $user = User::make();

            $issued = $user->otp('pair-device')->generate();

            expect($issued->purpose)->toBe('pair-device')
                ->and($issued->code)->toMatch('/^\d{6}$/')
                ->and($this->sentMails())->toBeEmpty()
                ->and($user->otp('pair-device')->pending())->toBeTrue();

            Event::assertDispatched(OtpGenerated::class, fn ($event) => $event->id === $issued->id);
            Event::assertNotDispatched(OtpSent::class);
        });

        it('applies per-call overrides', function () {
            $this->freezeTime();
            $user = User::make();

            $issued = Otp::for($user)->purpose('confirm-transfer')
                ->length(8)->alphanumeric()->ttl(300)->maxAttempts(3)
                ->generate();

            expect($issued->code)->toMatch('/^[23456789A-Z]{8}$/')
                ->and($issued->expiresAt->getTimestamp())->toBe(now()->addSeconds(300)->getTimestamp());

            $user->otp('confirm-transfer')->verify('WRONG');
            $user->otp('confirm-transfer')->verify('WRONG');

            expect($user->otp('confirm-transfer')->verify('WRONG')->status)->toBe(OtpStatus::TooManyAttempts);
        });

        it('applies purpose config over the defaults', function () {
            config()->set('otpkit.purposes.delete-account', ['length' => 8, 'max_attempts' => 3]);

            expect(User::make()->otp('delete-account')->generate()->code)->toMatch('/^\d{8}$/');
        });

        it('reports and kills pending codes', function () {
            $user = User::make();

            expect($user->otp('data-export')->pending())->toBeFalse();

            $code = $user->otp('data-export')->generate()->code;
            expect($user->otp('data-export')->pending())->toBeTrue();

            $user->otp('data-export')->invalidate();

            expect($user->otp('data-export')->pending())->toBeFalse()
                ->and($user->otp('data-export')->verify($code)->status)->toBe(OtpStatus::NotFound);
        });

        it('keeps one active code per subject and purpose', function () {
            $user = User::make();

            $first = $user->otp()->generate();
            $second = $user->otp()->generate();

            expect(Otp::isCurrent($first->id))->toBeFalse()
                ->and(Otp::isCurrent($second->id))->toBeTrue()
                ->and($user->otp()->verify($first->code)->ok())->toBe($first->code === $second->code)
                ->and(otpRecord($user)->id)->toBe($second->id);
        });

        it('checks without consuming, and verify consumes', function () {
            $user = User::make();
            $code = $user->otp('delete-account')->generate()->code;

            expect($user->otp('delete-account')->check($code)->ok())->toBeTrue()
                ->and($user->otp('delete-account')->check($code)->ok())->toBeTrue()
                ->and($user->otp('delete-account')->pending())->toBeTrue()
                ->and($user->otp('delete-account')->verify($code)->ok())->toBeTrue()
                ->and($user->otp('delete-account')->check($code)->status)->toBe(OtpStatus::AlreadyUsed);
        });

        it('counts failed checks as attempts', function () {
            $user = User::make();
            $code = $user->otp()->maxAttempts(3)->generate()->code;

            expect($user->otp()->check($code === '000000' ? '111111' : '000000')->attemptsRemaining)->toBe(2)
                ->and($user->otp()->check($code === '000000' ? '111111' : '000000')->attemptsRemaining)->toBe(1)
                ->and($user->otp()->check($code)->ok())->toBeTrue();
        });
    });
}
