<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use TecClub\OtpKit\Enums\OtpStatus;
use TecClub\OtpKit\Facades\Otp;
use TecClub\OtpKit\Rules\ValidOtp;
use TecClub\OtpKit\Tests\Fixtures\ConfirmDeletion;
use TecClub\OtpKit\Tests\Fixtures\DeleteAccountRequest;
use TecClub\OtpKit\Tests\Fixtures\User;

function validateCode(mixed $code, ValidOtp $rule): Illuminate\Validation\Validator
{
    return Validator::make(['code' => $code], ['code' => ['required', $rule]]);
}

it('checks by default, leaving the code active', function () {
    $user = User::make();
    $code = $user->otp('delete-account')->generate()->code;

    expect(validateCode($code, new ValidOtp($user, 'delete-account'))->passes())->toBeTrue()
        ->and($user->otp('delete-account')->pending())->toBeTrue()
        ->and($user->otp('delete-account')->verify($code)->ok())->toBeTrue();
});

it('consumes the code with ->consume()', function () {
    $user = User::make();
    $code = $user->otp('delete-account')->generate()->code;

    expect(validateCode($code, (new ValidOtp($user, 'delete-account'))->consume())->passes())->toBeTrue()
        ->and($user->otp('delete-account')->pending())->toBeFalse()
        ->and($user->otp('delete-account')->verify($code)->status)->toBe(OtpStatus::AlreadyUsed);
});

it('fails with the collapsed message and counts the attempt', function () {
    $user = User::make();
    $code = $user->otp('delete-account')->generate()->code;
    $rule = new ValidOtp($user, 'delete-account');

    $validator = validateCode(wrongCode($code), $rule);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('code'))->toBe('The code is invalid or has expired.')
        ->and($rule->result()->status)->toBe(OtpStatus::Invalid)
        ->and(otpRecord($user, 'delete-account')->attempts)->toBe(1);
});

it('rejects non-string input without touching the code', function () {
    $user = User::make();
    $user->otp('delete-account')->generate();

    expect(validateCode(['123456'], new ValidOtp($user, 'delete-account'))->fails())->toBeTrue()
        ->and(otpRecord($user, 'delete-account')->attempts)->toBe(0);
});

it('works for address subjects and the destination binding', function () {
    config()->set('otpkit.purposes.verify-email', ['bind_to' => ['destination']]);
    $user = User::make();
    $code = $user->otp('verify-email')->sendTo('mail', 'new@example.com')->generate()->code;

    expect(validateCode($code, (new ValidOtp($user, 'verify-email'))->destination('other@example.com'))->fails())->toBeTrue()
        ->and(validateCode($code, (new ValidOtp($user, 'verify-email'))->destination('new@example.com'))->passes())->toBeTrue();

    $guestCode = Otp::to('mail', 'guest@example.com')->purpose('guest-checkout')->generate()->code;

    expect(validateCode($guestCode, new ValidOtp(Otp::to('mail', 'guest@example.com')->purpose('guest-checkout')))->passes())->toBeTrue();
});

it('works in a Form Request', function () {
    Route::post('/delete-account', fn (DeleteAccountRequest $request) => response()->noContent());
    $user = User::make();
    $code = $user->otp('delete-account')->generate()->code;

    $this->postJson('/delete-account', ['user_id' => $user->id, 'code' => wrongCode($code)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code' => 'The code is invalid or has expired.']);

    $this->postJson('/delete-account', ['user_id' => $user->id, 'code' => $code])->assertNoContent();
    expect($user->otp('delete-account')->pending())->toBeTrue();

    $this->postJson('/delete-account', ['user_id' => $user->id, 'code' => $code, 'consume' => true])->assertNoContent();
    expect($user->otp('delete-account')->pending())->toBeFalse();
});

it('works in a Livewire component', function () {
    $user = User::make();
    $code = $user->otp('delete-account')->generate()->code;

    Livewire::test(ConfirmDeletion::class, ['userId' => $user->id])
        ->set('code', wrongCode($code))
        ->call('delete')
        ->assertHasErrors(['code'])
        ->assertSet('deleted', false)
        ->set('code', $code)
        ->call('delete')
        ->assertHasNoErrors()
        ->assertSet('deleted', true);

    expect($user->otp('delete-account')->pending())->toBeFalse();
});
