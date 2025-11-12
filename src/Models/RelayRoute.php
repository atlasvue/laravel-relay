<?php

declare(strict_types=1);

namespace AtlasRelay\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Configured route definitions used for AutoRouting decisions defined in the Routing PRD.
 *
 * @property positive-int $id
 * @property string|null $identifier
 * @property string $method
 * @property string $path
 * @property string $type
 * @property string $destination_url
 * @property array<string, mixed>|null $headers
 * @property bool $enabled
 */
class RelayRoute extends AtlasModel
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'headers' => 'array',
        'retry_policy' => 'array',
        'is_retry' => 'boolean',
        'is_delay' => 'boolean',
        'enabled' => 'boolean',
        'retry_seconds' => 'integer',
        'retry_max_attempts' => 'integer',
        'delay_seconds' => 'integer',
        'timeout_seconds' => 'integer',
        'http_timeout_seconds' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    protected function tableNameConfigKey(): string
    {
        return 'atlas-relay.tables.relay_routes';
    }

    protected function defaultTableName(): string
    {
        return 'atlas_relay_routes';
    }
}
