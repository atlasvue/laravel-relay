<?php

declare(strict_types=1);

namespace Atlas\Relay\Facades;

use Atlas\Relay\RelayBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * Facade entrypoint that mirrors the fluent API defined by the PRD.
 *
 * @method static RelayBuilder request(Request $request)
 * @method static RelayBuilder payload(mixed $payload)
 * @method static RelayBuilder type(\Atlas\Relay\Enums\RelayType $type)
 * @method static RelayBuilder provider(?string $provider)
 * @method static RelayBuilder setReferenceId(?string $referenceId)
 * @method static RelayBuilder guard(?string $guard)
 * @method static \Atlas\Relay\Support\RelayHttpClient http()
 * @method static \Atlas\Relay\Models\Relay cancel(\Atlas\Relay\Models\Relay $relay)
 */
class Relay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'atlas-relay.manager';
    }
}
