<?php

declare(strict_types=1);

namespace AtlasRelay\Services;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Enums\RelayStatus;
use AtlasRelay\Events\RelayAttemptStarted;
use AtlasRelay\Events\RelayCompleted;
use AtlasRelay\Events\RelayFailed;
use AtlasRelay\Models\Relay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Provides lifecycle utilities for cancelling or replaying relays in accordance with PRD rules.
 */
class RelayLifecycleService
{
    public function startAttempt(Relay $relay): Relay
    {
        $now = $this->now();

        $relay->forceFill([
            'status' => RelayStatus::PROCESSING,
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
            'status' => RelayStatus::COMPLETED,
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
            'status' => RelayStatus::FAILED,
            'failure_reason' => $failure->value,
            'failed_at' => $relay->failed_at ?? $now,
            'processing_finished_at' => $now,
            'last_attempt_duration_ms' => $durationMs,
        ], $attributes))->save();

        Event::dispatch(new RelayFailed($relay, $failure, $durationMs));

        return $relay;
    }

    public function recordResponse(Relay $relay, ?int $status, mixed $payload): Relay
    {
        $relay->forceFill([
            'response_status' => $status,
            'response_payload' => $payload,
        ])->save();

        return $relay;
    }

    public function recordExceptionResponse(Relay $relay, Throwable $exception): Relay
    {
        $maxBytes = (int) config('atlas-relay.lifecycle.exception_response_max_bytes', 1024);
        $summary = $this->formatExceptionSummary($exception);

        return $this->recordResponse($relay, null, $this->truncateString($summary, $maxBytes));
    }

    public function cancel(Relay $relay, ?RelayFailure $reason = null): Relay
    {
        $relay->forceFill([
            'status' => RelayStatus::CANCELLED,
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
            'status' => RelayStatus::QUEUED,
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

    private function formatExceptionSummary(Throwable $exception): string
    {
        $summary = sprintf(
            '%s: %s in %s:%d',
            $exception::class,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );

        $trace = $exception->getTrace();

        if ($trace === []) {
            return $summary;
        }

        $frames = array_slice($trace, 0, 3, true);
        $preview = [];

        foreach ($frames as $index => $frame) {
            $preview[] = $this->formatTraceFrame($frame, $index);
        }

        return $summary.PHP_EOL.implode(PHP_EOL, $preview);
    }

    /**
     * @param  array{class?:string,type?:string,function?:string,file?:string,line?:int}  $frame
     */
    private function formatTraceFrame(array $frame, int $index): string
    {
        $callable = $frame['function'] ?? '[internal]';

        if (isset($frame['class'])) {
            $callable = ($frame['class']).($frame['type'] ?? '').$callable;
        }

        $location = '[internal]';

        if (isset($frame['file'], $frame['line'])) {
            $location = sprintf('%s:%s', $frame['file'], (string) $frame['line']);
        }

        return sprintf('#%d %s (%s)', $index, $callable, $location);
    }

    private function truncateString(string $value, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $suffix = '...';
        $limit = max(0, $maxBytes - strlen($suffix));

        $truncated = function_exists('mb_strcut')
            ? (string) mb_strcut($value, 0, $limit, 'UTF-8')
            : substr($value, 0, $limit);

        return $truncated.$suffix;
    }
}
