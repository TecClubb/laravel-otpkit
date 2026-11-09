<?php

namespace TecClub\OtpKit\Testing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use PHPUnit\Framework\Assert as PHPUnit;
use TecClub\OtpKit\Contracts\OtpStore;
use TecClub\OtpKit\OtpManager;
use TecClub\OtpKit\PendingOtp;
use TecClub\OtpKit\Policy;
use TecClub\OtpKit\Results\IssuedOtp;
use TecClub\OtpKit\Results\VerificationResult;
use TecClub\OtpKit\Subject;

/**
 * Swapped in by Otp::fake(): an in-memory store, no notifications sent, every code recorded.
 */
class OtpFake extends OtpManager
{
    private InMemoryStore $fakeStore;

    private ?string $fixedCode = null;

    /** @var list<array{subject: string, purpose: string, issued: IssuedOtp}> */
    private array $issued = [];

    /** @var list<array{subject: string, purpose: string, issued: IssuedOtp, notification: Notification}> */
    private array $sent = [];

    /** @var list<array{subject: string, purpose: string, result: VerificationResult}> */
    private array $verifications = [];

    public static function make(Container $container, ?string $code = null): self
    {
        /** @var self $fake */
        $fake = $container->make(self::class);
        $fake->fakeStore = new InMemoryStore;
        $fake->fixedCode = $code;

        return $fake;
    }

    public function store(): OtpStore
    {
        return $this->fakeStore;
    }

    public function verify(PendingOtp $pending, string $code, ?string $destination, bool $consume): VerificationResult
    {
        $result = parent::verify($pending, $code, $destination, $consume);

        if ($consume) {
            $this->verifications[] = ['subject' => $pending->subject->key, 'purpose' => $pending->getPurpose(), 'result' => $result];
        }

        return $result;
    }

    public function assertSent(Model|Subject|string $subject, string $purpose = 'default', ?int $times = null): void
    {
        $count = count($this->matching($this->sent, $subject, $purpose));

        if ($times === null) {
            PHPUnit::assertGreaterThan(0, $count, "No code was sent for purpose [{$purpose}].");
        } else {
            PHPUnit::assertSame($times, $count, "Expected {$times} code(s) sent for purpose [{$purpose}], got {$count}.");
        }
    }

    public function assertNotSent(Model|Subject|string $subject, string $purpose = 'default'): void
    {
        PHPUnit::assertCount(0, $this->matching($this->sent, $subject, $purpose), "A code was sent for purpose [{$purpose}].");
    }

    public function assertVerified(Model|Subject|string $subject, string $purpose = 'default'): void
    {
        $verified = array_filter($this->matching($this->verifications, $subject, $purpose), fn ($entry) => $entry['result']->ok());

        PHPUnit::assertNotEmpty($verified, "No code was verified for purpose [{$purpose}].");
    }

    public function assertNotVerified(Model|Subject|string $subject, string $purpose = 'default'): void
    {
        $verified = array_filter($this->matching($this->verifications, $subject, $purpose), fn ($entry) => $entry['result']->ok());

        PHPUnit::assertEmpty($verified, "A code was verified for purpose [{$purpose}].");
    }

    /**
     * The plaintext of the last code issued (sent or generated) for the subject and purpose.
     */
    public function lastCode(Model|Subject|string $subject, string $purpose = 'default'): ?string
    {
        $matches = $this->matching($this->issued, $subject, $purpose);

        return $matches === [] ? null : end($matches)['issued']->code;
    }

    protected function issue(PendingOtp $pending, Policy $policy): IssuedOtp
    {
        $issued = parent::issue($pending, $policy);

        $this->issued[] = ['subject' => $pending->subject->key, 'purpose' => $policy->purpose, 'issued' => $issued];

        return $issued;
    }

    protected function makeCode(Policy $policy): string
    {
        return $this->fixedCode ?? parent::makeCode($policy);
    }

    protected function dispatchNotification(PendingOtp $pending, IssuedOtp $issued, object $notifiable, Notification $notification): void
    {
        $this->sent[] = [
            'subject' => $pending->subject->key,
            'purpose' => $issued->purpose,
            'issued' => $issued,
            'notification' => $notification,
        ];
    }

    /**
     * @template T of array{subject: string, purpose: string}
     *
     * @param  list<T>  $entries
     * @return list<T>
     */
    private function matching(array $entries, Model|Subject|string $subject, string $purpose): array
    {
        $key = Subject::from($subject)->key;

        return array_values(array_filter($entries, fn ($entry) => $entry['subject'] === $key && $entry['purpose'] === $purpose));
    }
}
