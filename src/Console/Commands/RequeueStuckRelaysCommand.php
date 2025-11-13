<?php

declare(strict_types=1);

namespace Atlas\Relay\Console\Commands;

use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Events\AutomationMetrics;
use Atlas\Relay\Events\RelayRequeued;
use Atlas\Relay\Models\Relay;
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
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('processing_at')
                    ->orWhere('processing_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->chunkById($chunkSize, function ($relays) use (&$count): void {
                foreach ($relays as $relay) {
                    $relay->forceFill([
                        'status' => RelayStatus::QUEUED,
                        'processing_at' => null,
                        'completed_at' => null,
                        'next_retry_at' => now(),
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
