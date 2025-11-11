<?php

declare(strict_types=1);

namespace AtlasRelay;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Models\Relay;
use AtlasRelay\Routing\RouteContext as RoutingContext;
use AtlasRelay\Routing\Router;
use AtlasRelay\Routing\RouteResult;
use AtlasRelay\Routing\RoutingException;
use AtlasRelay\Services\RelayCaptureService;
use AtlasRelay\Support\RelayContext;
use Illuminate\Http\Request;

/**
 * Fluent builder that mirrors the relay lifecycle defined in the PRDs and persists relays via the capture service.
 */
class RelayBuilder
{
    private ?Request $request;

    private mixed $payload;

    private ?string $mode = null;

    /** @var array<string, mixed> */
    private array $lifecycleOverrides = [];

    /** @var array<string, mixed> */
    private array $meta = [];

    /** @var array<string, array<int, string>> */
    private array $validationErrors = [];

    private ?RelayFailure $failureReason = null;

    private string $status = 'queued';

    private ?Relay $capturedRelay = null;

    private ?RouteResult $routeResult = null;

    /** @var array<string, string> */
    private array $routeHeaders = [];

    /** @var array<string, string> */
    private array $routeParameters = [];

    public function __construct(
        private readonly RelayCaptureService $captureService,
        private readonly Router $router,
        ?Request $request = null,
        mixed $payload = null
    ) {
        $this->request = $request;
        $this->payload = $payload;
    }

    public function request(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    public function payload(mixed $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function mode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function retry(?int $seconds = null, ?int $maxAttempts = null): self
    {
        $this->lifecycleOverrides['is_retry'] = true;

        if ($seconds !== null) {
            $this->lifecycleOverrides['retry_seconds'] = $seconds;
        }

        if ($maxAttempts !== null) {
            $this->lifecycleOverrides['retry_max_attempts'] = $maxAttempts;
        }

        return $this;
    }

    public function disableRetry(): self
    {
        $this->lifecycleOverrides['is_retry'] = false;

        return $this;
    }

    public function delay(?int $seconds): self
    {
        $this->lifecycleOverrides['is_delay'] = $seconds !== null && $seconds > 0;
        $this->lifecycleOverrides['delay_seconds'] = $seconds;

        return $this;
    }

    public function timeout(?int $seconds): self
    {
        $this->lifecycleOverrides['timeout_seconds'] = $seconds;

        return $this;
    }

    public function httpTimeout(?int $seconds): self
    {
        $this->lifecycleOverrides['http_timeout_seconds'] = $seconds;

        return $this;
    }

    public function maxAttempts(?int $maxAttempts): self
    {
        $this->lifecycleOverrides['max_attempts'] = $maxAttempts;

        return $this;
    }

    public function meta(array $meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    public function mergeMeta(array $meta): self
    {
        $this->meta = array_replace_recursive($this->meta, $meta);

        return $this;
    }

    public function validationError(string $field, string $message): self
    {
        $this->validationErrors[$field][] = $message;

        return $this;
    }

    public function failWith(RelayFailure $failure, string $status = 'failed'): self
    {
        $this->failureReason = $failure;
        $this->status = $status;

        return $this;
    }

    public function status(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Captures the relay record and returns the persisted model.
     */
    public function capture(): Relay
    {
        return $this->ensureRelayCaptured();
    }

    /**
     * Returns the most recently captured relay model, if present.
     */
    public function relay(): ?Relay
    {
        return $this->capturedRelay;
    }

    /**
     * Exposes the current relay snapshot for introspection/testing.
     */
    public function context(): RelayContext
    {
        return new RelayContext(
            $this->request,
            $this->payload,
            $this->mode,
            $this->lifecycleOverrides,
            $this->meta,
            $this->failureReason,
            $this->status,
            $this->validationErrors,
            $this->routeResult?->id,
            $this->routeResult?->identifier,
            $this->routeResult?->type,
            $this->routeResult?->destination,
            $this->routeHeaders,
            $this->routeParameters
        );
    }

    public function event(callable $callback): self
    {
        $this->mode ??= 'event';
        $this->ensureRelayCaptured();

        return $this;
    }

    public function dispatchEvent(callable $callback): self
    {
        $this->mode ??= 'dispatch_event';
        $this->ensureRelayCaptured();

        return $this;
    }

    public function dispatchAutoRoute(): self
    {
        return $this->handleAutoRoute('auto_route');
    }

    public function autoRouteImmediately(): self
    {
        return $this->handleAutoRoute('auto_route_immediate');
    }

    public function http(): self
    {
        $this->mode ??= 'http';
        $this->ensureRelayCaptured();

        return $this;
    }

    public function dispatch(mixed $job): self
    {
        $this->mode ??= 'dispatch';
        $this->ensureRelayCaptured();

        return $this;
    }

    public function dispatchSync(mixed $job): self
    {
        $this->mode ??= 'dispatch_sync';
        $this->ensureRelayCaptured();

        return $this;
    }

    public function dispatchChain(array $jobs): self
    {
        $this->mode ??= 'dispatch_chain';
        $this->ensureRelayCaptured();

        return $this;
    }

    private function handleAutoRoute(string $mode): self
    {
        try {
            $routeResult = $this->router->resolve($this->buildRouteContext());
            $this->applyRouteResult($routeResult);
            $this->mode ??= $mode;
            $this->status = 'queued';
        } catch (RoutingException $exception) {
            $this->mode ??= $mode;
            $this->failWith($exception->failure);
            $this->validationError('route', $exception->getMessage());
        }

        $this->ensureRelayCaptured();

        return $this;
    }

    private function applyRouteResult(RouteResult $route): void
    {
        $this->routeResult = $route;
        $this->routeHeaders = $route->headers;
        $this->routeParameters = $route->parameters;

        $this->mergeLifecycleDefaults($route->lifecycle);
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private function mergeLifecycleDefaults(array $defaults): void
    {
        foreach ($defaults as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (array_key_exists($key, $this->lifecycleOverrides)) {
                continue;
            }

            $this->lifecycleOverrides[$key] = $value;
        }
    }

    private function buildRouteContext(): RoutingContext
    {
        return RoutingContext::fromRequest($this->request, $this->payload);
    }

    private function ensureRelayCaptured(): Relay
    {
        if ($this->capturedRelay instanceof Relay) {
            return $this->capturedRelay;
        }

        $this->capturedRelay = $this->captureService->capture($this->context());

        return $this->capturedRelay;
    }
}
