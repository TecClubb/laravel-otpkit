<?php

namespace TecClub\OtpKit\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;
use TecClub\OtpKit\Facades\Otp;
use TecClub\OtpKit\Results\IssuedOtp;

class OneTimePasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly IssuedOtp $otp)
    {
        $this->afterCommit();

        if ($connection = config('otpkit.queue.connection')) {
            $this->onConnection($connection);
        }

        if ($queue = config('otpkit.queue.name')) {
            $this->onQueue($queue);
        }
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Drops a queued code that was consumed, expired or replaced before the worker ran.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return Otp::isCurrent($this->otp->id);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subjectLine())
            ->markdown('otpkit::mail.code', [
                'code' => $this->otp->code,
                'expiresInMinutes' => $this->otp->expiresInMinutes(),
            ]);
    }

    protected function subjectLine(): string
    {
        $key = 'otpkit::mail.subjects.'.$this->otp->purpose;

        return Lang::has($key)
            ? (string) __($key, ['app' => config('app.name')])
            : (string) __('otpkit::mail.subject', ['app' => config('app.name')]);
    }
}
