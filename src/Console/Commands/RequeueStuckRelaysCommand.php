<?php

declare(strict_types=1);

namespace AtlasRelay\Console\Commands;

use AtlasRelay\Enums\RelayStatus;
use AtlasRelay\Events\AutomationMetrics;
use AtlasRelay\Events\RelayRequeued;
use AtlasRelay\Models\Relay;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Class RequeueStuckRelaysCommand
 *
 * Resets relays stuck in processing to queued status, delivering the
 * requeue automation defined in PRD — Atlas Relay (Automation Jobs).
 */
class RequeueStuckRelaysCommand extends Command
{
    protected $signature = 'atlas-relay:requeue-stuck {--chunk=100 : Number of relays per chunk}';

    protected $description = 'Requeue relays stuck in processing beyond the configured threshold.';

    public function handle(): int
    {
        $thresholdMinutes = (int) config('atlas-relay.automation.stuck_threshold_minutes', 10);
        $cutoff = Carbon::now()->subMinutes($thresholdMinutes);
        $chunkSize = (int) $this->option('chunk');

        $start = microtime(true);
        $count = 0;

        Relay::query()
            ->where('status', RelayStatus::PROCESSING->value)
            ->whereNull('archived_at')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('processing_started_at')
                    ->orWhere('processing_started_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->chunkById($chunkSize, function ($relays) use (&$count): void {
                foreach ($relays as $relay) {
                    $relay->forceFill([
                        'status' => RelayStatus::QUEUED,
                        'processing_started_at' => null,
                        'processing_finished_at' => null,
                        'last_attempt_duration_ms' => null,
                        'retry_at' => now(),
                    ])->save();

                    $count++;
                    event(new RelayRequeued($relay));
                    Log::info('atlas-relay:requeue-stuck', ['relay_id' => $relay->id]);
                }
            });

        $duration = (int) round((microtime(true) - $start) * 1000);

        event(new AutomationMetrics('requeue_stuck', $count, $duration, [
            'threshold_minutes' => $thresholdMinutes,
        ]));

        $this->info("Requeued {$count} stuck relays.");

        return self::SUCCESS;
    }
}
