<?php

declare(strict_types=1);

namespace Atlas\Relay\Models;

use Atlas\Relay\Enums\HttpMethod;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Enums\RelayType;

/**
 * Represents the authoritative live relay record specified in the Receive/Send Webhook Relay PRDs.
 *
 * @property positive-int $id
 * @property RelayType $type
 * @property string|null $source_ip
 * @property string|null $provider
 * @property string|null $reference_id
 * @property array<string, mixed>|null $headers
 * @property array<mixed>|null $payload
 * @property RelayStatus $status
 * @property HttpMethod|null $method
 * @property string|null $url
 * @property int|null $response_http_status
 * @property array<mixed>|string|null $response_payload
 * @property int|null $failure_reason
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
        'type' => RelayType::class,
        'status' => RelayStatus::class,
        'method' => HttpMethod::class,
        'response_http_status' => 'integer',
        'failure_reason' => 'integer',
        'processing_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    protected function tableNameConfigKey(): string
    {
        return 'atlas-relay.tables.relays';
    }

    protected function defaultTableName(): string
    {
        return 'atlas_relays';
    }
}
