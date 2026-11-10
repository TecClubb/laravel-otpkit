<?php

namespace TecClub\OtpKit;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use TecClub\OtpKit\Commands\PruneCommand;
use TecClub\OtpKit\Contracts\TokenIssuer;
use TecClub\OtpKit\Generators\GeneratorRegistry;
use TecClub\OtpKit\Login\LoginService;
use TecClub\OtpKit\Login\SanctumTokenIssuer;
use TecClub\OtpKit\Stores\StoreManager;
use TecClub\OtpKit\Support\Limiter;
use TecClub\OtpKit\Support\RequestContext;

class OtpKitServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('otpkit')
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations()
            ->hasMigration('create_one_time_passwords_table')
            ->hasCommand(PruneCommand::class)
            ->hasInstallCommand(fn (InstallCommand $command) => $command
                ->publishConfigFile()
                ->publishMigrations()
                ->askToRunMigrations());
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(GeneratorRegistry::class);
        $this->app->singleton(StoreManager::class);
        $this->app->singleton(PolicyResolver::class);
        $this->app->singleton(RequestContext::class);
        $this->app->singleton(Limiter::class);
        $this->app->singleton(Verifier::class);
        $this->app->singleton(OtpManager::class);

        $this->app->singleton(Hasher::class, fn (Application $app) => new Hasher($app['config']->get('app.key')));

        $this->app->singleton(LoginService::class);

        // A binding made by the app (Passport, JWT) wins over the configured class.
        $this->app->bindIf(TokenIssuer::class, fn (Application $app) => $app->make(
            $app['config']->get('otpkit.login.token_issuer') ?? SanctumTokenIssuer::class
        ));
    }

    public function packageBooted(): void
    {
        if ($this->app['config']->get('otpkit.prune.schedule', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command(PruneCommand::class)->daily();
            });
        }

        // After every provider has booted, so custom generators and stores registered by the app are known.
        $this->app->booted(function (Application $app) {
            $app->make(PolicyResolver::class)->validateConfig();

            // Resolving the cache driver checks the cache store supports locks, so fail at boot.
            if ($app['config']->get('otpkit.store') === 'cache') {
                $app->make(StoreManager::class)->driver();
            }

            if ($app['config']->get('otpkit.login.enabled', false)) {
                $app->make(LoginService::class)->validateConfig();
            }
        });
    }
}
