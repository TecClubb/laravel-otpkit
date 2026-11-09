<?php

namespace TecClub\OtpKit\Stores;

use Illuminate\Support\Manager;
use TecClub\OtpKit\Contracts\OtpStore;

/**
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
}
