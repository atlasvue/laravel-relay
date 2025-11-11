<?php

declare(strict_types=1);

namespace AtlasRelay;

use AtlasRelay\Contracts\RelayManagerInterface;
use AtlasRelay\Models\Relay;
use AtlasRelay\Services\RelayCaptureService;
use AtlasRelay\Services\RelayLifecycleService;
use Illuminate\Http\Request;

/**
 * Default RelayManager that hands back configured builders per the PRD.
 */
class RelayManager implements RelayManagerInterface
{
    public function __construct(
        private readonly RelayCaptureService $captureService,
        private readonly RelayLifecycleService $lifecycleService
    ) {}

    public function request(Request $request): RelayBuilder
    {
        return new RelayBuilder($this->captureService, $request);
    }

    public function payload(mixed $payload): RelayBuilder
    {
        return (new RelayBuilder($this->captureService))->payload($payload);
    }

    public function cancel(Relay $relay): Relay
    {
        return $this->lifecycleService->cancel($relay);
    }

    public function replay(Relay $relay): Relay
    {
        return $this->lifecycleService->replay($relay);
    }
}
