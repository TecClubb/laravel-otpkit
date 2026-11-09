<?php

use TecClub\OtpKit\Contracts\CodeGenerator;
use TecClub\OtpKit\Exceptions\InvalidConfigException;
use TecClub\OtpKit\Generators\AlphanumericGenerator;
use TecClub\OtpKit\Generators\NumericGenerator;
use TecClub\OtpKit\OtpKit;
use TecClub\OtpKit\Tests\Fixtures\User;

it('generates numeric codes of the configured length', function () {
    $code = User::make()->otp()->generate()->code;

    expect($code)->toMatch('/^\d{6}$/');
});

it('generates alphanumeric codes without ambiguous characters', function () {
    $codes = collect(range(1, 50))->map(fn () => (new AlphanumericGenerator)->generate(12))->implode('');

    expect($codes)->toMatch('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]+$/')
        ->and($codes)->not->toMatch('/[01OIL]/');
});

it('supports lengths from 4 to 12, per call', function (int $length) {
    $issued = User::make()->otp()->length($length)->alphanumeric()->generate();

    expect(strlen($issued->code))->toBe($length)
        ->and($issued->length)->toBe($length);
})->with([4, 8, 12]);

it('rejects lengths outside 4 to 12', function (int $length) {
    User::make()->otp()->length($length)->generate();
})->with([3, 13])->throws(InvalidConfigException::class);

it('uses random_int, never rand, mt_rand or Str::random', function () {
    foreach ([NumericGenerator::class, AlphanumericGenerator::class] as $class) {
        $source = file_get_contents((new ReflectionClass($class))->getFileName());

        expect($source)->toContain('random_int(')
            ->not->toMatch('/\b(mt_)?rand\(/')
            ->not->toContain('Str::random');
    }
});

it('normalizes input before comparing', function () {
    $user = User::make();
    $code = $user->otp()->alphanumeric()->generate()->code;

    $typed = '  '.strtolower(substr($code, 0, 3)).'-'.strtolower(substr($code, 3)).' ';

    expect($user->otp()->verify($typed)->ok())->toBeTrue();
});

it('accepts custom code generators registered by name', function () {
    OtpKit::generator('sevens', fn () => new class implements CodeGenerator
    {
        public function generate(int $length): string
        {
            return str_repeat('7', $length);
        }
    });

    expect(User::make()->otp()->charset('sevens')->length(5)->generate()->code)->toBe('77777');
});
