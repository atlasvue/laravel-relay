<?php

declare(strict_types=1);

namespace Atlas\Relay\Models;

use Atlas\Relay\Enums\HttpMethod;
use Atlas\Relay\Enums\RelayStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents the authoritative live relay record specified in the Payload Capture, Routing, and Outbound Delivery PRDs.
 *
 * @property positive-int $id
 * @property string|null $source_ip
 * @property string|null $provider
 * @property string|null $reference_id
 * @property array<string, mixed>|null $headers
 * @property array<mixed>|null $payload
 * @property RelayStatus $status
 * @property string|null $mode
 * @property int|null $route_id
 * @property HttpMethod|null $method
 * @property string|null $url
 * @property int|null $response_http_status
 * @property array<mixed>|string|null $response_payload
 * @property int|null $failure_reason
 * @property int $attempt_count
 * @property \Carbon\CarbonImmutable|null $next_retry_at
 * @property \Carbon\CarbonImmutable|null $processing_at
 * @property \Carbon\CarbonImmutable|null $completed_at
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @property RelayRoute|null $route
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
        'method' => HttpMethod::class,
        'attempt_count' => 'integer',
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
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', now());
    }

    /**
     * @phpstan-return BelongsTo<RelayRoute, Relay>
     */
    public function route(): BelongsTo
    {
        /** @var BelongsTo<RelayRoute, Relay> $relation */
        $relation = $this->belongsTo(RelayRoute::class, 'route_id');

        return $relation;
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
