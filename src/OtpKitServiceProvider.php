<?php

namespace TecClub\OtpKit;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use TecClub\OtpKit\Commands\PruneCommand;
use TecClub\OtpKit\Generators\GeneratorRegistry;
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
    }

    public function packageBooted(): void
    {
        if ($this->app['config']->get('otpkit.prune.schedule', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $schedule->command(PruneCommand::class)->daily();
            });
        }

        // After every provider has booted, so custom generators registered by the app are known.
        $this->app->booted(fn (Application $app) => $app->make(PolicyResolver::class)->validateConfig());
    }
}
