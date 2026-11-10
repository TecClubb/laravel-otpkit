<?php

namespace TecClub\OtpKit\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Symfony\Component\Mime\Email;
use TecClub\OtpKit\OtpKitServiceProvider;
use TecClub\OtpKit\Stores\StoreManager;

class TestCase extends Orchestra
{
    public const REDIS_PREFIX = 'otpkit_test:';

    /**
     * Config changes applied before the app boots, for tests that call refreshApplication().
     *
     * @var (\Closure(Repository): void)|null
     */
    public static ?\Closure $configure = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
    }

    protected function tearDown(): void
    {
        static::$configure = null;

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            SanctumServiceProvider::class,
            OtpKitServiceProvider::class,
        ];
    }

    /**
     * Point the package at one of the stores the shared suites run against.
     */
    public function useStore(string $store): void
    {
        match ($store) {
            'database' => config()->set('otpkit.store', 'database'),
            'cache' => config()->set(['otpkit.store' => 'cache', 'otpkit.cache_store' => 'array']),
            'redis' => $this->useRedisStore(),
        };

        app(StoreManager::class)->forgetDrivers();
    }

    /**
     * Redis runs only when OTPKIT_TEST_REDIS_HOST is set, on its own database
     * (OTPKIT_TEST_REDIS_DB, default 15), and refuses to clear one holding
     * anything but this suite's keys.
     */
    protected function useRedisStore(): void
    {
        if (! getenv('OTPKIT_TEST_REDIS_HOST') || ! extension_loaded('redis')) {
            $this->markTestSkipped('Set OTPKIT_TEST_REDIS_HOST (and install ext-redis) to run against Redis.');
        }

        config()->set([
            'database.redis.client' => 'phpredis',
            'database.redis.options.prefix' => self::REDIS_PREFIX,
            'database.redis.otpkit' => [
                'host' => getenv('OTPKIT_TEST_REDIS_HOST'),
                'port' => getenv('OTPKIT_TEST_REDIS_PORT') ?: 6379,
                'database' => getenv('OTPKIT_TEST_REDIS_DB') ?: 15,
            ],
            'cache.stores.otpkit-redis' => ['driver' => 'redis', 'connection' => 'otpkit', 'lock_connection' => 'otpkit'],
            'otpkit.store' => 'cache',
            'otpkit.cache_store' => 'otpkit-redis',
        ]);

        $client = Redis::connection('otpkit')->client();
        $foreign = array_filter($client->keys('*'), fn (string $key) => ! str_starts_with($key, self::REDIS_PREFIX));

        if ($foreign !== []) {
            $this->markTestSkipped('Redis database '.(getenv('OTPKIT_TEST_REDIS_DB') ?: 15).' holds other keys; refusing to clear it.');
        }

        $client->flushDB();
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $config->set('app.name', 'OtpKit Test');
        $config->set('database.default', 'testing');
        $config->set('cache.default', 'array');
        $config->set('mail.default', 'array');
        $config->set('queue.default', 'sync');
        $config->set('session.driver', 'array');

        $config->set('auth.guards.web', ['driver' => 'session', 'provider' => 'users']);
        $config->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'admins']);
        $config->set('auth.providers.users', ['driver' => 'eloquent', 'model' => Fixtures\User::class]);
        $config->set('auth.providers.admins', ['driver' => 'eloquent', 'model' => Fixtures\Admin::class]);

        $config->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => env('OTPKIT_TEST_MYSQL_HOST', '127.0.0.1'),
            'port' => env('OTPKIT_TEST_MYSQL_PORT', '3306'),
            'database' => env('OTPKIT_TEST_MYSQL_DATABASE', 'otpkit'),
            'username' => env('OTPKIT_TEST_MYSQL_USERNAME', 'root'),
            'password' => env('OTPKIT_TEST_MYSQL_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);

        $config->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('OTPKIT_TEST_PGSQL_HOST', '127.0.0.1'),
            'port' => env('OTPKIT_TEST_PGSQL_PORT', '5432'),
            'database' => env('OTPKIT_TEST_PGSQL_DATABASE', 'otpkit'),
            'username' => env('OTPKIT_TEST_PGSQL_USERNAME', 'postgres'),
            'password' => env('OTPKIT_TEST_PGSQL_PASSWORD', 'postgres'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
        ]);

        if (static::$configure) {
            (static::$configure)($config);
        }
    }

    protected function createTables(?string $connection = null): void
    {
        $schema = Schema::connection($connection);

        $schema->dropIfExists('users');
        $schema->dropIfExists('devices');
        $schema->dropIfExists(config('otpkit.database.table'));

        $schema->dropIfExists('admins');
        $schema->dropIfExists('personal_access_tokens');

        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->rememberToken();
            $table->timestamps();
        });

        $schema->create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->string('email');
            $table->rememberToken();
            $table->timestamps();
        });

        $schema->create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $migration = include __DIR__.'/../database/migrations/create_one_time_passwords_table.php.stub';

        $previous = config('database.default');
        config()->set('database.default', $connection ?? $previous);

        try {
            $migration->up();

            foreach (glob(__DIR__.'/../vendor/laravel/sanctum/database/migrations/*.php') ?: [] as $sanctumMigration) {
                (include $sanctumMigration)->up();
            }
        } finally {
            config()->set('database.default', $previous);
        }
    }

    /**
     * @return Collection<int, Email>
     */
    public function sentMails(): Collection
    {
        /** @var ArrayTransport $transport */
        $transport = Mail::mailer('array')->getSymfonyTransport();

        return $transport->messages()->map(fn ($sent) => $sent->getOriginalMessage());
    }

    /**
     * Pull the code out of the default mail template.
     */
    public function codeFromMail(Email $mail): string
    {
        preg_match('/letter-spacing: 6px; text-align: center;">([A-Z0-9]+)</', (string) $mail->getHtmlBody(), $matches);

        return $matches[1] ?? throw new \RuntimeException('No code found in mail body.');
    }
}
