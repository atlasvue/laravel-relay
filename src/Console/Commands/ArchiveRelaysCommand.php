<?php

declare(strict_types=1);

namespace AtlasRelay\Console\Commands;

use AtlasRelay\Events\AutomationMetrics;
use AtlasRelay\Models\Relay;
use AtlasRelay\Models\RelayArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Class ArchiveRelaysCommand
 *
 * Moves completed or failed relays into `atlas_relay_archives` to uphold the
 * retention workflow defined in PRD — Archiving & Logging (Archiving Process).
 */
class ArchiveRelaysCommand extends Command
{
    protected $signature = 'atlas-relay:archive {--chunk= : Number of relays per chunk}';

    protected $description = 'Moves completed/failed relays into the archive table based on retention rules.';

    protected function configure(): void
    {
        parent::configure();

        if ($this->getDefinition()->hasOption('chunk')) {
            $this->getDefinition()
                ->getOption('chunk')
                ->setDefault(config('atlas-relay.archiving.chunk_size', 500));
        }
    }

    public function handle(): int
    {
        $chunkSize = (int) ($this->option('chunk') ?? config('atlas-relay.archiving.chunk_size', 500));
        $archiveAfterDays = (int) config('atlas-relay.archiving.archive_after_days', 30);
        $cutoff = Carbon::now()->subDays($archiveAfterDays);

        $start = microtime(true);
        $count = 0;

        Relay::query()
            ->whereNull('archived_at')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($relays) use (&$count): void {
                if ($relays->isEmpty()) {
                    return;
                }

                DB::transaction(function () use ($relays, &$count): void {
                    $timestamp = Carbon::now();
                    $records = $relays->map(function (Relay $relay) use ($timestamp): array {
                        $attributes = $relay->getAttributes();

                        foreach ($attributes as $key => $value) {
                            if ($value instanceof \DateTimeInterface) {
                                $attributes[$key] = Carbon::instance($value)->toDateTimeString();
                            }
                        }

                        $attributes['archived_at'] = $attributes['archived_at'] ?? $timestamp->toDateTimeString();

                        return $attributes;
                    })->all();

                    RelayArchive::query()->insert($records);

                    Relay::query()->whereIn('id', $relays->pluck('id'))->delete();

                    $count += count($records);
                });
            });

        $duration = (int) round((microtime(true) - $start) * 1000);

        event(new AutomationMetrics('archive', $count, $duration, [
            'cutoff' => $cutoff->toDateTimeString(),
        ]));

        $this->info("Archived {$count} relays.");

        return self::SUCCESS;
    }
}
