<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use TecClub\OtpKit\Tests\Fixtures\User;

/*
 * Two processes verify the same correct code at the same moment. Exactly one
 * may succeed. Needs real locks across processes: row locks on MySQL and
 * PostgreSQL (SQLite has none), and Cache::lock() on Redis. CI services only.
 */
it('lets exactly one of two parallel correct verifies succeed', function (string $store, string $hostEnv) {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Needs the pcntl and posix extensions.');
    }

    if (! getenv($hostEnv)) {
        $this->markTestSkipped("Set {$hostEnv} to run against {$store}.");
    }

    if ($store === 'redis') {
        $this->useStore('redis');
    } else {
        config()->set('database.default', $store);
        $this->createTables($store);
    }

    $user = User::make();
    $code = $user->otp('data-export')->generate()->code;

    // Children must open their own connections, never share the parent's socket.
    $store === 'redis' ? Redis::purge('otpkit') : DB::purge($store);

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

    expect($results)->toBe(['already_used', 'verified'])
        ->and(otpRecord($user, 'data-export')->isConsumed())->toBeTrue();

    if ($store !== 'redis') {
        DB::purge($store);
        $this->createTables($store);
    }
})->with([
    'mysql' => ['mysql', 'OTPKIT_TEST_MYSQL_HOST'],
    'pgsql' => ['pgsql', 'OTPKIT_TEST_PGSQL_HOST'],
    'redis' => ['redis', 'OTPKIT_TEST_REDIS_HOST'],
]);
