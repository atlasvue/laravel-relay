<?php

declare(strict_types=1);

namespace AtlasRelay\Services;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Events\RelayAttemptStarted;
use AtlasRelay\Events\RelayCompleted;
use AtlasRelay\Events\RelayFailed;
use AtlasRelay\Models\Relay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/**
 * Provides lifecycle utilities for cancelling or replaying relays in accordance with PRD rules.
 */
class RelayLifecycleService
{
    public function startAttempt(Relay $relay): Relay
    {
        $now = $this->now();

        $relay->forceFill([
            'status' => 'processing',
            'attempt_count' => ($relay->attempt_count ?? 0) + 1,
            'first_attempted_at' => $relay->first_attempted_at ?? $now,
            'last_attempted_at' => $now,
            'processing_started_at' => $relay->processing_started_at ?? $now,
        ])->save();

        Event::dispatch(new RelayAttemptStarted($relay));

        return $relay;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function markCompleted(Relay $relay, array $attributes = [], ?int $durationMs = null): Relay
    {
        $now = $this->now();

        $relay->forceFill(array_merge([
            'status' => 'completed',
            'failure_reason' => null,
            'processing_finished_at' => $now,
            'completed_at' => $relay->completed_at ?? $now,
            'last_attempt_duration_ms' => $durationMs,
        ], $attributes))->save();

        Event::dispatch(new RelayCompleted($relay, $durationMs));

        return $relay;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function markFailed(
        Relay $relay,
        RelayFailure $failure,
        array $attributes = [],
        ?int $durationMs = null
    ): Relay {
        $now = $this->now();

        $relay->forceFill(array_merge([
            'status' => 'failed',
            'failure_reason' => $failure->value,
            'failed_at' => $relay->failed_at ?? $now,
            'processing_finished_at' => $now,
            'last_attempt_duration_ms' => $durationMs,
        ], $attributes))->save();

        Event::dispatch(new RelayFailed($relay, $failure, $durationMs));

        return $relay;
    }

    public function recordResponse(Relay $relay, ?int $status, mixed $payload, bool $truncated = false): Relay
    {
        $relay->forceFill([
            'response_status' => $status,
            'response_payload' => $payload,
            'response_payload_truncated' => $truncated,
        ])->save();

        return $relay;
    }

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

        return $relay;
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

        return $relay;
    }

    protected function now(): Carbon
    {
        return now();
    }
}
