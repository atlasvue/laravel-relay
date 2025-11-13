<?php

declare(strict_types=1);

namespace Atlas\Relay\Console\Commands;

use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Events\AutomationMetrics;
use Atlas\Relay\Events\RelayRequeued;
use Atlas\Relay\Models\Relay;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Class RetryOverdueRelaysCommand
 *
 * Requeues relays whose retry window has elapsed, fulfilling the retry
 * automation cycle outlined in PRD — Atlas Relay (Automation Jobs).
 */
class RetryOverdueRelaysCommand extends Command
{
    protected $signature = 'atlas-relay:retry-overdue {--chunk=100 : Number of relays to process per chunk}';

    protected $description = 'Requeue relays whose retry window has elapsed.';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $start = microtime(true);
        $count = 0;

        Relay::query()
            ->dueForRetry()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($relays) use (&$count): void {
                foreach ($relays as $relay) {
                    $relay->forceFill([
                        'status' => RelayStatus::QUEUED,
                        'next_retry_at' => null,
                        'failure_reason' => null,
                        'processing_at' => null,
                        'completed_at' => null,
                    ])->save();

                    $count++;

                    event(new RelayRequeued($relay));
                    Log::info('atlas-relay:retry-overdue', ['relay_id' => $relay->id]);
                }
            });

        $duration = (int) round((microtime(true) - $start) * 1000);

        event(new AutomationMetrics('retry_overdue', $count, $duration));

        $this->info("Retried {$count} relays.");

        return self::SUCCESS;
    }
}
