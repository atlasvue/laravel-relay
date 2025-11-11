<?php

declare(strict_types=1);

namespace AtlasRelay\Console\Commands;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Events\AutomationMetrics;
use AtlasRelay\Models\Relay;
use AtlasRelay\Services\RelayLifecycleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Class EnforceRelayTimeoutsCommand
 *
 * Marks relays as failed when processing exceeds configured time limits,
 * fulfilling the timeout enforcement automation in PRD — Atlas Relay
 * (Automation Jobs).
 */
class EnforceRelayTimeoutsCommand extends Command
{
    protected $signature = 'atlas-relay:enforce-timeouts {--chunk=100 : Number of relays per chunk}';

    protected $description = 'Marks relays that exceeded their timeout configuration as failed.';

    public function handle(RelayLifecycleService $lifecycle): int
    {
        $chunkSize = (int) $this->option('chunk');
        $bufferSeconds = (int) config('atlas-relay.automation.timeout_buffer_seconds', 0);

        $start = microtime(true);
        $count = 0;

        Relay::query()
            ->where('status', 'processing')
            ->whereNull('archived_at')
            ->whereNotNull('processing_started_at')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($relays) use (&$count, $lifecycle, $bufferSeconds): void {
                foreach ($relays as $relay) {
                    $timeout = $relay->timeout_seconds ?? $relay->http_timeout_seconds;

                    if ($timeout === null || $timeout <= 0) {
                        continue;
                    }

                    if (! $relay->processing_started_at instanceof \DateTimeInterface) {
                        continue;
                    }

                    $deadline = Carbon::parse($relay->processing_started_at)
                        ->addSeconds($timeout + $bufferSeconds);

                    if ($deadline->isPast()) {
                        $lifecycle->markFailed($relay, RelayFailure::ROUTE_TIMEOUT);
                        $count++;
                    }
                }
            });

        $duration = (int) round((microtime(true) - $start) * 1000);

        event(new AutomationMetrics('enforce_timeouts', $count, $duration, [
            'buffer_seconds' => $bufferSeconds,
        ]));

        $this->info("Timed out {$count} relays.");

        return self::SUCCESS;
    }
}
