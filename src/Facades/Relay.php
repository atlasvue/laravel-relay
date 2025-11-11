<?php

declare(strict_types=1);

namespace AtlasRelay\Facades;

use AtlasRelay\RelayBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * Facade entrypoint that mirrors the fluent API defined by the PRD.
 *
 * @method static RelayBuilder request(Request $request)
 * @method static RelayBuilder payload(mixed $payload)
 * @method static \AtlasRelay\Models\Relay cancel(\AtlasRelay\Models\Relay $relay)
 * @method static \AtlasRelay\Models\Relay replay(\AtlasRelay\Models\Relay $relay)
 */
class Relay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'atlas-relay.manager';
    }
}
