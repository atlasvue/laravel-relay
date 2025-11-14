<?php

declare(strict_types=1);

namespace Atlas\Relay;

use Atlas\Relay\Contracts\RelayManagerInterface;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Services\InboundGuardService;
use Atlas\Relay\Services\RelayCaptureService;
use Atlas\Relay\Services\RelayDeliveryService;
use Atlas\Relay\Services\RelayLifecycleService;
use Atlas\Relay\Support\RelayHttpClient;
use Illuminate\Http\Request;

/**
 * Default RelayManager that hands back configured builders per the PRD.
 */
class RelayManager implements RelayManagerInterface
{
    public function __construct(
        private readonly RelayCaptureService $captureService,
        private readonly RelayLifecycleService $lifecycleService,
        private readonly RelayDeliveryService $deliveryService,
        private readonly InboundGuardService $guardService
    ) {}

    public function request(Request $request): RelayBuilder
    {
        return $this->newBuilder($request);
    }

    public function payload(mixed $payload): RelayBuilder
    {
        return $this->newBuilder()->payload($payload);
    }

    public function provider(?string $provider): RelayBuilder
    {
        return $this->newBuilder()->provider($provider);
    }

    public function setReferenceId(?string $referenceId): RelayBuilder
    {
        return $this->newBuilder()->setReferenceId($referenceId);
    }

    public function guard(?string $guard): RelayBuilder
    {
        return $this->newBuilder()->guard($guard);
    }

    public function http(): RelayHttpClient
    {
        return $this->newBuilder()->http();
    }

    public function cancel(Relay $relay): Relay
    {
        return $this->lifecycleService->cancel($relay);
    }

    private function newBuilder(?Request $request = null): RelayBuilder
    {
        return new RelayBuilder(
            $this->captureService,
            $this->deliveryService,
            $this->lifecycleService,
            $this->guardService,
            $request
        );
    }
}
