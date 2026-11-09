<?php

use Illuminate\Support\Facades\DB;
use TecClub\OtpKit\Tests\Fixtures\User;

/*
 * Two processes verify the same correct code at the same moment against a
 * database with real row locks. Exactly one may succeed. SQLite has no row
 * locks, so this runs on MySQL and PostgreSQL (CI services) only.
 */
it('lets exactly one of two parallel correct verifies succeed', function (string $connection, string $hostEnv) {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions.');
    }

    if (! getenv($hostEnv)) {
        $this->markTestSkipped("Set {$hostEnv} to run against {$connection}.");
    }

    config()->set('database.default', $connection);
    $this->createTables($connection);

    $user = User::make();
    $code = $user->otp('data-export')->generate()->code;

    // Children must open their own connections, never share the parent's socket.
    DB::purge($connection);

    $results = [];
    $files = [];
    $pids = [];
    $startAt = microtime(true) + 0.5;

    foreach ([0, 1] as $i) {
        $files[$i] = tempnam(sys_get_temp_dir(), 'otpkit');
        $pid = pcntl_fork();

        if ($pid === 0) {
            while (microtime(true) < $startAt) {
                usleep(1000);
            }

            try {
                $status = User::query()->find($user->id)->otp('data-export')->verify($code)->status->value;
            } catch (Throwable $e) {
                $status = 'error: '.$e->getMessage();
            }

            file_put_contents($files[$i], $status);
            posix_kill(getmypid(), SIGKILL);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    foreach ($files as $file) {
        $results[] = file_get_contents($file);
        unlink($file);
    }

    sort($results);

    expect($results)->toBe(['already_used', 'verified']);

    DB::purge($connection);
    expect($user->oneTimePasswords()->whereNotNull('consumed_at')->count())->toBe(1);

    $this->createTables($connection);
})->with([
    'mysql' => ['mysql', 'OTPKIT_TEST_MYSQL_HOST'],
    'pgsql' => ['pgsql', 'OTPKIT_TEST_PGSQL_HOST'],
]);
