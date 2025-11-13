<?php

declare(strict_types=1);

namespace Atlas\Relay\Models;

use Atlas\Relay\Enums\DestinationMethod;
use Atlas\Relay\Enums\RelayStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Represents the authoritative live relay record specified in the Payload Capture, Routing, and Outbound Delivery PRDs.
 *
 * @property positive-int $id
 * @property string|null $source
 * @property array<string, mixed>|null $headers
 * @property array<mixed>|null $payload
 * @property RelayStatus $status
 * @property string|null $mode
 * @property int|null $route_id
 * @property string|null $route_identifier
 * @property DestinationMethod|null $destination_method
 * @property string|null $destination_url
 * @property int|null $response_http_status
 * @property array<mixed>|string|null $response_payload
 * @property int|null $failure_reason
 * @property bool $is_retry
 * @property int|null $retry_seconds
 * @property int|null $retry_max_attempts
 * @property int $attempt_count
 * @property bool $is_delay
 * @property int|null $delay_seconds
 * @property int|null $timeout_seconds
 * @property int|null $http_timeout_seconds
 * @property \Carbon\CarbonImmutable|null $next_retry_at
 * @property \Carbon\CarbonImmutable|null $processing_at
 * @property \Carbon\CarbonImmutable|null $completed_at
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
        'status' => RelayStatus::class,
        'destination_method' => DestinationMethod::class,
        'is_retry' => 'boolean',
        'is_delay' => 'boolean',
        'retry_seconds' => 'integer',
        'retry_max_attempts' => 'integer',
        'attempt_count' => 'integer',
        'delay_seconds' => 'integer',
        'timeout_seconds' => 'integer',
        'http_timeout_seconds' => 'integer',
        'response_http_status' => 'integer',
        'failure_reason' => 'integer',
        'route_id' => 'integer',
        'next_retry_at' => 'immutable_datetime',
        'processing_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
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
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now());
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
