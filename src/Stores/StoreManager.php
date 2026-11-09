<?php

namespace TecClub\OtpKit\Stores;

use Illuminate\Support\Manager;
use TecClub\OtpKit\Contracts\OtpStore;

/**
 * Drivers are resolved once per process, so the cache driver's lock check
 * runs once, not per code.
 *
 * @internal
 *
 * @method OtpStore driver(?string $driver = null)
 */
class StoreManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('otpkit.store', 'database');
    }

    protected function createDatabaseDriver(): OtpStore
    {
        return new DatabaseStore;
    }

    protected function createCacheDriver(): OtpStore
    {
        $storeName = $this->config->get('otpkit.cache_store');

        return new CacheStore(
            $this->container->make('cache')->store($storeName),
            (string) $this->config->get('otpkit.cache.prefix', 'otpkit'),
            (int) $this->config->get('otpkit.cache.grace', 60),
            $storeName,
        );
    }
}
