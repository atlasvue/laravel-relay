<?php

declare(strict_types=1);

namespace AtlasRelay\Contracts;

use AtlasRelay\Models\Relay;
use AtlasRelay\RelayBuilder;
use Illuminate\Http\Request;

/**
 * Contract exposing the entrypoints defined in the Atlas Relay PRD
 * for instantiating fluent relay builders independent of the host app.
 */
interface RelayManagerInterface
{
    public function request(Request $request): RelayBuilder;

    public function payload(mixed $payload): RelayBuilder;

    public function cancel(Relay $relay): Relay;

    public function replay(Relay $relay): Relay;
}
