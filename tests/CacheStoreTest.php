<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use TecClub\OtpKit\Enums\OtpStatus;
use TecClub\OtpKit\Exceptions\InvalidConfigException;
use TecClub\OtpKit\Facades\Otp;
use TecClub\OtpKit\Stores\CacheStore;
use TecClub\OtpKit\Stores\StoreManager;
use TecClub\OtpKit\Subject;
use TecClub\OtpKit\Tests\Fixtures\User;
use TecClub\OtpKit\Tests\TestCase;

beforeEach(fn () => $this->useStore('cache'));

it('resolves the cache driver', function () {
    expect(Otp::store())->toBeInstanceOf(CacheStore::class);
});

it('keeps one key per active code, named by hashed subject and purpose', function () {
    $user = User::make();
    $issued = $user->otp('data-export')->generate();

    $key = 'otpkit:'.hash('sha256', Subject::fromModel($user)->key).':data-export';
    $value = Cache::store('array')->get($key);

    expect($value['id'])->toBe($issued->id)
        ->and(json_encode($value))->not->toContain($issued->code);
});

it('uses the configured prefix', function () {
    config()->set('otpkit.cache.prefix', 'codes');
    $this->useStore('cache');
    $user = User::make();
    $user->otp()->generate();

    expect(Cache::store('array')->has('codes:'.hash('sha256', Subject::fromModel($user)->key).':default'))->toBeTrue();
});

it('keeps codes ttl + grace seconds so verify can still report Expired', function () {
    $user = User::make();
    $code = $user->otp()->generate()->code;

    $this->travel(150)->seconds();
    expect($user->otp()->verify($code)->status)->toBe(OtpStatus::Expired);

    $this->travel(31)->seconds();
    expect($user->otp()->verify($code)->status)->toBe(OtpStatus::NotFound);
});

it('throws a clear error for a cache store that cannot lock', function () {
    config()->set('cache.stores.apc', ['driver' => 'apc']);
    config()->set('otpkit.cache_store', 'apc');
    app(StoreManager::class)->forgetDrivers();

    expect(fn () => Otp::store())->toThrow(InvalidConfigException::class, 'names cache store [apc] which does not support atomic locks');
});

it('throws at boot when the configured cache store cannot lock', function () {
    TestCase::$configure = function ($config) {
        $config->set('cache.stores.apc', ['driver' => 'apc']);
        $config->set('otpkit.store', 'cache');
        $config->set('otpkit.cache_store', 'apc');
    };

    expect(fn () => $this->refreshApplication())->toThrow(InvalidConfigException::class, 'does not support atomic locks');
});

it('does not touch the database', function () {
    User::make()->otp()->generate();

    expect(DB::table('one_time_passwords')->count())->toBe(0);
});
