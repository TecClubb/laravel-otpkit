<?php

namespace TecClub\OtpKit\Tests\Fixtures;

use Livewire\Component;
use TecClub\OtpKit\Rules\ValidOtp;

class ConfirmDeletion extends Component
{
    public int $userId;

    public string $code = '';

    public bool $deleted = false;

    public function mount(int $userId): void
    {
        $this->userId = $userId;
    }

    public function delete(): void
    {
        $user = User::query()->findOrFail($this->userId);

        $this->validate([
            'code' => ['required', 'string', (new ValidOtp($user, 'delete-account'))->consume()],
        ]);

        $this->deleted = true;
    }

    public function render(): string
    {
        return <<<'HTML'
            <div>{{ $deleted ? 'deleted' : 'pending' }}</div>
            HTML;
    }
}
