<?php

namespace TecClub\OtpKit\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use TecClub\OtpKit\Models\OneTimePassword;

class PruneCommand extends Command
{
    protected $signature = 'otpkit:prune
        {--hours= : Keep expired and used codes this many hours (default: otpkit.prune.keep_for_hours)}
        {--chunk=1000 : Rows deleted per query}';

    protected $description = 'Delete expired and used one-time passwords';

    public function handle(): int
    {
        if (config('otpkit.store') !== 'database') {
            $this->components->info('OtpKit is not using the database store; nothing to prune.');

            return self::SUCCESS;
        }

        $hours = (int) ($this->option('hours') ?? config('otpkit.prune.keep_for_hours', 24));
        $chunk = max(1, (int) $this->option('chunk'));
        $cutoff = CarbonImmutable::now()->subHours($hours);
        $deleted = 0;

        do {
            $ids = OneTimePassword::query()
                ->where(fn ($query) => $query
                    ->where('expires_at', '<', $cutoff)
                    ->orWhere('consumed_at', '<', $cutoff))
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $deleted += OneTimePassword::query()->whereKey($ids->all())->delete();
            }
        } while ($ids->count() === $chunk);

        $this->components->info("Pruned {$deleted} one-time password(s).");

        return self::SUCCESS;
    }
}
