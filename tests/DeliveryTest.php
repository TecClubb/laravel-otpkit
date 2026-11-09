<?php

use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use TecClub\OtpKit\Notifications\OneTimePasswordNotification;
use TecClub\OtpKit\Tests\Fixtures\User;

foreach (otpStores() as $store) {
    describe("{$store} store", function () use ($store) {
        beforeEach(fn () => $this->useStore($store));

        it('sends the default template with the app mail config and no extra setup', function () {
            User::make()->otp()->send();

            $mail = $this->sentMails()->sole();
            $html = (string) $mail->getHtmlBody();

            expect($mail->getSubject())->toBe('Your OtpKit Test verification code')
                ->and($this->codeFromMail($mail))->toMatch('/^\d{6}$/')
                ->and($html)->toContain('This code expires in 2 minutes.')
                ->and($html)->toContain('If you didn')
                ->and($mail->getFrom()[0]->getAddress())->toBe(config('mail.from.address'));
        });

        it('uses a purpose-specific subject when the translation exists', function () {
            app('translator')->addLines(['mail.subjects.data-export' => 'Confirm your data export'], 'en', 'otpkit');

            User::make()->otp('data-export')->send();

            expect($this->sentMails()->sole()->getSubject())->toBe('Confirm your data export');
        });

        it('queues on the app default connection and queue unless configured', function () {
            Queue::fake();

            User::make()->otp()->send();

            Queue::assertPushed(SendQueuedNotifications::class, function ($job) {
                return $job->notification instanceof OneTimePasswordNotification
                    && $job->connection === null
                    && $job->queue === null;
            });
        });

        it('queues on the configured connection and queue', function () {
            Queue::fake();
            config()->set('otpkit.queue.connection', 'redis');
            config()->set('otpkit.queue.name', 'otp');

            User::make()->otp()->send();

            Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->connection === 'redis' && $job->queue === 'otp');
        });

        it('sends inline when the queue is disabled', function () {
            Queue::fake();
            config()->set('otpkit.queue.enabled', false);

            User::make()->otp()->send();

            Queue::assertNothingPushed();
            expect($this->sentMails())->toHaveCount(1);
        });

        it('drops a queued notification whose code was replaced before the worker ran', function () {
            Queue::fake();
            // Two sends within 30 seconds would otherwise hit the resend cooldown.
            config()->set('otpkit.rate_limits.send.cooldown', null);
            $user = User::make();

            $user->otp()->send();
            $user->otp()->send();

            $jobs = Queue::pushed(SendQueuedNotifications::class)->values();
            expect($jobs)->toHaveCount(2);

            $jobs[0]->handle(app(ChannelManager::class));
            expect($this->sentMails())->toHaveCount(0);

            $jobs[1]->handle(app(ChannelManager::class));
            expect($this->sentMails())->toHaveCount(1);
        });

        it('drops a queued notification whose code was consumed or expired', function () {
            Queue::fake();
            $user = User::make();

            $user->otp('a')->send();
            $user->otp('b')->send();
            [$consumedJob, $expiredJob] = Queue::pushed(SendQueuedNotifications::class)->values()->all();

            $user->otp('a')->verify($consumedJob->notification->otp->code);
            $consumedJob->handle(app(ChannelManager::class));

            $this->travel(3)->minutes();
            $expiredJob->handle(app(ChannelManager::class));

            expect($this->sentMails())->toHaveCount(0);
        });

        it('can be swapped for a custom notification per purpose', function () {
            config()->set('otpkit.purposes.sms-login', ['notification' => CustomOtpNotification::class]);

            User::make()->otp('sms-login')->send();

            expect($this->sentMails()->sole()->getSubject())->toBe('Custom subject');
        });
    });
}

class CustomOtpNotification extends OneTimePasswordNotification
{
    protected function subjectLine(): string
    {
        return 'Custom subject';
    }
}
