<?php

use Illuminate\Support\Facades\File;
use TecClub\OtpKit\Exceptions\InvalidConfigException;
use TecClub\OtpKit\Exceptions\UnknownPurposeException;
use TecClub\OtpKit\PolicyResolver;
use TecClub\OtpKit\Tests\Fixtures\User;

it('registers the otpkit config with working defaults', function () {
    expect(config('otpkit.store'))->toBe('database')
        ->and(config('otpkit.defaults.length'))->toBe(6)
        ->and(config('otpkit.defaults.ttl'))->toBe(120);
});

it('validates the config file', function (array $config, string $message) {
    foreach ($config as $key => $value) {
        config()->set($key, $value);
    }

    expect(fn () => app(PolicyResolver::class)->validateConfig())
        ->toThrow(InvalidConfigException::class, $message);
})->with([
    'length too short' => [['otpkit.defaults.length' => 3], 'length'],
    'length too long' => [['otpkit.defaults.length' => 13], 'length'],
    'unknown charset' => [['otpkit.defaults.charset' => 'emoji'], 'charset'],
    'zero ttl' => [['otpkit.defaults.ttl' => 0], 'ttl'],
    'bad binding' => [['otpkit.defaults.bind_to' => ['cookie']], 'bind_to'],
    'purpose typo' => [['otpkit.purposes' => ['export' => ['lenght' => 8]]], 'unknown keys'],
    'purpose length' => [['otpkit.purposes' => ['export' => ['length' => 20]]], 'purpose [export]'],
    'bad cooldown' => [['otpkit.rate_limits.send.cooldown' => 'soon'], 'cooldown'],
    'bad notification' => [['otpkit.notification' => stdClass::class], 'notification'],
]);

it('throws for unknown purposes only in strict mode', function () {
    $user = User::make();

    expect($user->otp('anything')->generate()->purpose)->toBe('anything');

    config()->set('otpkit.strict_purposes', true);
    config()->set('otpkit.purposes', ['data-export' => []]);

    expect($user->otp('data-export')->generate()->purpose)->toBe('data-export')
        ->and($user->otp()->generate()->purpose)->toBe('default')
        ->and(fn () => $user->otp('anything')->generate())->toThrow(UnknownPurposeException::class);
});

it('publishes the migration stub with a publish-time timestamp', function () {
    $before = File::glob(database_path('migrations/*_create_one_time_passwords_table.php'));
    File::delete($before);

    $this->artisan('vendor:publish', ['--tag' => 'otpkit-migrations'])->assertSuccessful();

    $published = File::glob(database_path('migrations/*_create_one_time_passwords_table.php'));

    expect($published)->toHaveCount(1)
        ->and(basename($published[0]))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_one_time_passwords_table\.php$/');

    File::delete($published);
});

it('installs config and migration with otpkit:install', function () {
    File::delete(config_path('otpkit.php'));
    File::delete(File::glob(database_path('migrations/*_create_one_time_passwords_table.php')));

    $this->artisan('otpkit:install')
        ->expectsConfirmation('Would you like to run the migrations now?', 'no')
        ->assertSuccessful();

    expect(File::exists(config_path('otpkit.php')))->toBeTrue()
        ->and(File::glob(database_path('migrations/*_create_one_time_passwords_table.php')))->toHaveCount(1);

    File::delete(config_path('otpkit.php'));
    File::delete(File::glob(database_path('migrations/*_create_one_time_passwords_table.php')));
});
