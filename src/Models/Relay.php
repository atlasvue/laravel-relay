<?php

declare(strict_types=1);

namespace AtlasRelay\Models;

use AtlasRelay\Enums\RelayStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Represents the authoritative live relay record specified in the Payload Capture, Routing, and Outbound Delivery PRDs.
 *
 * @property positive-int $id
 * @property string|null $request_source
 * @property array<string, mixed>|null $headers
 * @property array<mixed>|null $payload
 * @property RelayStatus $status
 * @property string|null $mode
 * @property int|null $route_id
 * @property string|null $route_identifier
 * @property string|null $destination_type
 * @property string|null $destination_url
 * @property int|null $response_status
 * @property array<mixed>|null $response_payload
 * @property bool $response_payload_truncated
 * @property int|null $failure_reason
 * @property bool $is_retry
 * @property int|null $retry_seconds
 * @property int|null $retry_max_attempts
 * @property int $attempt_count
 * @property int|null $max_attempts
 * @property bool $is_delay
 * @property int|null $delay_seconds
 * @property int|null $timeout_seconds
 * @property int|null $http_timeout_seconds
 * @property int|null $last_attempt_duration_ms
 * @property \Carbon\CarbonImmutable|null $retry_at
 * @property \Carbon\CarbonImmutable|null $first_attempted_at
 * @property \Carbon\CarbonImmutable|null $last_attempted_at
 * @property \Carbon\CarbonImmutable|null $processing_started_at
 * @property \Carbon\CarbonImmutable|null $processing_finished_at
 * @property \Carbon\CarbonImmutable|null $completed_at
 * @property \Carbon\CarbonImmutable|null $failed_at
 * @property \Carbon\CarbonImmutable|null $cancelled_at
 * @property \Carbon\CarbonImmutable|null $archived_at
 * @property array<mixed>|null $meta
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 */
class Relay extends AtlasModel
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'headers' => 'array',
        'payload' => 'array',
        'response_payload' => 'array',
        'meta' => 'array',
        'status' => RelayStatus::class,
        'is_retry' => 'boolean',
        'is_delay' => 'boolean',
        'response_payload_truncated' => 'boolean',
        'retry_seconds' => 'integer',
        'retry_max_attempts' => 'integer',
        'attempt_count' => 'integer',
        'max_attempts' => 'integer',
        'delay_seconds' => 'integer',
        'timeout_seconds' => 'integer',
        'http_timeout_seconds' => 'integer',
        'last_attempt_duration_ms' => 'integer',
        'response_status' => 'integer',
        'failure_reason' => 'integer',
        'route_id' => 'integer',
        'retry_at' => 'immutable_datetime',
        'first_attempted_at' => 'immutable_datetime',
        'last_attempted_at' => 'immutable_datetime',
        'processing_started_at' => 'immutable_datetime',
        'processing_finished_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'failed_at' => 'immutable_datetime',
        'cancelled_at' => 'immutable_datetime',
        'archived_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDueForRetry(Builder $query): Builder
    {
        return $query
            ->where('is_retry', true)
            ->whereNull('archived_at')
            ->whereNotNull('retry_at')
            ->where('retry_at', '<=', now());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnarchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    protected function tableNameConfigKey(): string
    {
        return 'atlas-relay.tables.relays';
    }

    protected function defaultTableName(): string
    {
        return 'atlas_relays';
    }
}
