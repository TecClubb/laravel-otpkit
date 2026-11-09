<?php

namespace TecClub\OtpKit\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use TecClub\OtpKit\OtpKitServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            OtpKitServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
    }
}
