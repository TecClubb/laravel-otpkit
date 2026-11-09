<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use TecClub\OtpKit\Tests\Fixtures\User;
use TecClub\OtpKit\Tests\TestCase;

it('deletes expired and used codes older than keep_for_hours', function () {
    $user = User::make();

    $user->otp('expired-long-ago')->generate();
    $used = $user->otp('used-long-ago')->generate();
    $user->otp('used-long-ago')->verify($used->code);

    $this->travel(25)->hours();

    $user->otp('fresh')->generate();
    $user->otp('expired-recently')->ttl(60)->generate();
    $this->travel(2)->minutes();

    $this->artisan('otpkit:prune')->assertSuccessful();

    expect(DB::table('one_time_passwords')->pluck('purpose')->sort()->values()->all())
        ->toBe(['expired-recently', 'fresh']);
});

it('prunes in chunks', function () {
    $user = User::make();

    foreach (range(1, 5) as $i) {
        $user->otp("purpose-{$i}")->generate();
    }

    $this->travel(2)->days();

    $this->artisan('otpkit:prune', ['--chunk' => 2])
        ->expectsOutputToContain('Pruned 5')
        ->assertSuccessful();

    expect(DB::table('one_time_passwords')->count())->toBe(0);
});

it('does nothing on the cache store', function () {
    config()->set('otpkit.store', 'cache');

    $this->artisan('otpkit:prune')
        ->expectsOutputToContain('nothing to prune')
        ->assertSuccessful();
});

it('schedules pruning daily without host setup', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'otpkit:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});

it('does not schedule pruning when prune.schedule is false', function () {
    // The schedule hook is registered at boot, so restart the app with the option off.
    TestCase::$configure = fn ($config) => $config->set('otpkit.prune.schedule', false);
    $this->refreshApplication();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'otpkit:prune'));

    expect($events)->toBeEmpty();
});
