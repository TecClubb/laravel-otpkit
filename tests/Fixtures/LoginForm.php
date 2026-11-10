<?php

namespace TecClub\OtpKit\Tests\Fixtures;

use Livewire\Component;
use TecClub\OtpKit\Facades\OtpLogin;

/**
 * Logs in through the OtpLogin service directly: no HTTP calls to the package endpoints.
 */
class LoginForm extends Component
{
    public string $email = '';

    public string $code = '';

    public bool $codeSent = false;

    public function sendCode(): void
    {
        OtpLogin::send($this->email, guard: 'web');

        $this->codeSent = true;
    }

    public function login(): mixed
    {
        // Errors added with addError() persist across Livewire requests.
        $this->resetErrorBag('code');

        $result = OtpLogin::attempt($this->email, $this->code, guard: 'web');

        if (! $result->ok()) {
            $this->addError('code', (string) $result->message());

            return null;
        }

        return redirect()->intended('/dashboard');
    }

    public function render(): string
    {
        return <<<'HTML'
            <div>{{ $codeSent ? 'sent' : 'idle' }}</div>
            HTML;
    }
}
