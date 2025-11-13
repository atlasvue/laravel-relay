<?php

declare(strict_types=1);

namespace Atlas\Relay\Services;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Models\Relay;
use Illuminate\Support\Carbon;
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
            'processing_at' => $now,
            'completed_at' => null,
        ])->save();

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
            'completed_at' => $now,
            'next_retry_at' => null,
        ], $attributes))->save();

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
            'completed_at' => $now,
            'next_retry_at' => null,
        ], $attributes))->save();

        return $relay;
    }

    public function recordResponse(Relay $relay, ?int $status, mixed $payload): Relay
    {
        $relay->forceFill([
            'response_http_status' => $status,
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
            'completed_at' => $this->now(),
            'next_retry_at' => null,
        ])->save();

        return $relay;
    }

    public function replay(Relay $relay): Relay
    {
        $relay->forceFill([
            'status' => RelayStatus::QUEUED,
            'failure_reason' => null,
            'completed_at' => null,
            'next_retry_at' => null,
            'processing_at' => null,
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
