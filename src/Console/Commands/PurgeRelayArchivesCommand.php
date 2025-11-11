<?php

declare(strict_types=1);

namespace AtlasRelay\Console\Commands;

use AtlasRelay\Events\AutomationMetrics;
use AtlasRelay\Models\RelayArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Class PurgeRelayArchivesCommand
 *
 * Deletes archived relays that exceed retention, executing the purge cycle
 * prescribed by PRD — Archiving & Logging (Purge Process).
 */
class PurgeRelayArchivesCommand extends Command
{
    protected $signature = 'atlas-relay:purge-archives';

    protected $description = 'Deletes archived relays that exceeded the purge retention window.';

    public function handle(): int
    {
        $purgeAfterDays = (int) config('atlas-relay.archiving.purge_after_days', 180);
        $cutoff = Carbon::now()->subDays($purgeAfterDays);

        $start = microtime(true);

        $count = RelayArchive::query()
            ->whereNotNull('archived_at')
            ->where('archived_at', '<=', $cutoff)
            ->delete();

        $duration = (int) round((microtime(true) - $start) * 1000);

        event(new AutomationMetrics('purge_archives', $count, $duration, [
            'cutoff' => $cutoff->toDateTimeString(),
        ]));

        $this->info("Purged {$count} archived relays.");

        return self::SUCCESS;
    }
}
