<?php

declare(strict_types=1);

namespace AtlasRelay\Services;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Models\Relay;
use Illuminate\Support\Carbon;

/**
 * Provides lifecycle utilities for cancelling or replaying relays in accordance with PRD rules.
 */
class RelayLifecycleService
{
    public function cancel(Relay $relay, ?RelayFailure $reason = null): Relay
    {
        $relay->forceFill([
            'status' => 'cancelled',
            'failure_reason' => ($reason ?? RelayFailure::CANCELLED)->value,
            'cancelled_at' => $this->now(),
            'failed_at' => null,
            'completed_at' => null,
            'retry_at' => null,
        ])->save();

        return $relay->refresh();
    }

    public function replay(Relay $relay): Relay
    {
        $relay->forceFill([
            'status' => 'queued',
            'failure_reason' => null,
            'archived_at' => null,
            'cancelled_at' => null,
            'failed_at' => null,
            'completed_at' => null,
            'retry_at' => null,
            'processing_started_at' => null,
            'processing_finished_at' => null,
            'first_attempted_at' => null,
            'last_attempted_at' => null,
            'attempt_count' => 0,
        ])->save();

        return $relay->refresh();
    }

    protected function now(): Carbon
    {
        return now();
    }
}
