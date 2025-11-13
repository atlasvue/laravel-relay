<?php

declare(strict_types=1);

namespace Atlas\Relay\Console\Commands;

use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Models\Relay;
use Illuminate\Console\Command;

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
                }
            });

        $this->info("Retried {$count} relays.");

        return self::SUCCESS;
    }
}
