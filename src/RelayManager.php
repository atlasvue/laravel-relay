<?php

declare(strict_types=1);

namespace AtlasRelay;

use AtlasRelay\Contracts\RelayManagerInterface;
use AtlasRelay\Models\Relay;
use AtlasRelay\Routing\Router;
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
        private readonly RelayLifecycleService $lifecycleService,
        private readonly Router $router
    ) {}

    public function request(Request $request): RelayBuilder
    {
        return new RelayBuilder($this->captureService, $this->router, $request);
    }

    public function payload(mixed $payload): RelayBuilder
    {
        return (new RelayBuilder($this->captureService, $this->router))->payload($payload);
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
