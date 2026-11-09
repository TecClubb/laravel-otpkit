<?php

namespace TecClub\OtpKit\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Symfony\Component\Mime\Email;
use TecClub\OtpKit\OtpKitServiceProvider;

class TestCase extends Orchestra
{
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
            OtpKitServiceProvider::class,
        ];
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

        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email');
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
        } finally {
            config()->set('database.default', $previous);
        }
    }

    /**
     * @return Collection<int, Email>
     */
    protected function sentMails(): Collection
    {
        /** @var ArrayTransport $transport */
        $transport = Mail::mailer('array')->getSymfonyTransport();

        return $transport->messages()->map(fn ($sent) => $sent->getOriginalMessage());
    }

    /**
     * Pull the code out of the default mail template.
     */
    protected function codeFromMail(Email $mail): string
    {
        preg_match('/letter-spacing: 6px; text-align: center;">([A-Z0-9]+)</', (string) $mail->getHtmlBody(), $matches);

        return $matches[1] ?? throw new \RuntimeException('No code found in mail body.');
    }
}
