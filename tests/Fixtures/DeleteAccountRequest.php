<?php

namespace TecClub\OtpKit\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use TecClub\OtpKit\Rules\ValidOtp;

class DeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = User::query()->findOrFail($this->integer('user_id'));

        $rule = new ValidOtp($user, 'delete-account');

        return [
            'user_id' => ['required', 'integer'],
            'code' => ['required', 'string', $this->boolean('consume') ? $rule->consume() : $rule],
        ];
    }
}
