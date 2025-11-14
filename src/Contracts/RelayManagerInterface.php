<?php

declare(strict_types=1);

namespace Atlas\Relay\Contracts;

use Atlas\Relay\Models\Relay;
use Atlas\Relay\RelayBuilder;
use Atlas\Relay\Support\RelayHttpClient;
use Illuminate\Http\Request;

/**
 * Contract exposing the entrypoints defined in the Atlas Relay PRD
 * for instantiating fluent relay builders independent of the host app.
 */
interface RelayManagerInterface
{
    public function request(Request $request): RelayBuilder;

    public function payload(mixed $payload): RelayBuilder;

    public function provider(?string $provider): RelayBuilder;

    public function setReferenceId(?string $referenceId): RelayBuilder;

    public function guard(?string $guard): RelayBuilder;

    public function http(): RelayHttpClient;

    public function cancel(Relay $relay): Relay;
}
