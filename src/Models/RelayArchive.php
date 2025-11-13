<?php

declare(strict_types=1);

namespace Atlas\Relay\Models;

use Atlas\Relay\Enums\HttpMethod;
use Atlas\Relay\Enums\RelayStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Archived relay records retained per the Archiving & Logging PRD.
 */
class RelayArchive extends AtlasModel
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
        'archived_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEligibleForPurge(Builder $query, int $retentionDays): Builder
    {
        return $query
            ->whereNotNull('archived_at')
            ->where('archived_at', '<=', now()->subDays($retentionDays));
    }

    protected function tableNameConfigKey(): string
    {
        return 'atlas-relay.tables.relay_archives';
    }

    protected function defaultTableName(): string
    {
        return 'atlas_relay_archives';
    }
}
