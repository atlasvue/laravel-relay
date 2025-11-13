<?php

declare(strict_types=1);

namespace Atlas\Relay;

use Atlas\Relay\Enums\HttpMethod;
use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Routing\RouteContext as RoutingContext;
use Atlas\Relay\Routing\Router;
use Atlas\Relay\Routing\RouteResult;
use Atlas\Relay\Routing\RoutingException;
use Atlas\Relay\Services\RelayCaptureService;
use Atlas\Relay\Services\RelayDeliveryService;
use Atlas\Relay\Support\RelayContext;
use Atlas\Relay\Support\RelayHttpClient;
use Atlas\Relay\Support\RequestPayloadExtractor;
use Illuminate\Foundation\Bus\PendingChain;
use Illuminate\Foundation\Bus\PendingDispatch;
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

    /** @var array<string, array<int, string>> */
    private array $validationErrors = [];

    /**
     * @var array<string, array{name:string,value:string}>
     */
    private array $headers = [];

    private ?RelayFailure $failureReason = null;

    private RelayStatus $status = RelayStatus::QUEUED;

    private ?Relay $capturedRelay = null;

    private ?RouteResult $routeResult = null;

    private RequestPayloadExtractor $payloadExtractor;

    private ?string $resolvedMethod = null;

    private ?string $resolvedUrl = null;

    private ?string $provider = null;

    private ?string $referenceId = null;

    public function __construct(
        private readonly RelayCaptureService $captureService,
        private readonly Router $router,
        private readonly RelayDeliveryService $deliveryService,
        ?Request $request = null,
        mixed $payload = null,
        ?RequestPayloadExtractor $payloadExtractor = null
    ) {
        $this->payload = $payload;
        $this->request = null;
        $this->payloadExtractor = $payloadExtractor ?? new RequestPayloadExtractor;

        if ($request !== null) {
            $this->applyRequest($request);
        }
    }

    public function request(Request $request): self
    {
        $this->applyRequest($request);

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
        $this->lifecycleOverrides['retry_max_attempts'] = $maxAttempts;

        return $this;
    }

    public function validationError(string $field, string $message): self
    {
        $this->validationErrors[$field][] = $message;

        return $this;
    }

    public function failWith(RelayFailure $failure, RelayStatus $status = RelayStatus::FAILED): self
    {
        $this->failureReason = $failure;
        $this->status = $status;

        return $this;
    }

    public function status(RelayStatus $status): self
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
        $capturedMethod = $this->resolvedMethod
            ?? HttpMethod::tryFromMixed($this->request?->getMethod())?->value;

        $capturedUrl = $this->resolvedUrl ?? $this->request?->fullUrl();

        return new RelayContext(
            $this->request,
            $this->payload,
            $this->mode,
            $this->lifecycleOverrides,
            $this->failureReason,
            $this->status,
            $this->validationErrors,
            $this->routeResult?->id,
            $this->routeResult?->identifier,
            $capturedMethod,
            $capturedUrl,
            $this->provider,
            $this->referenceId,
            $this->resolvedHeaders()
        );
    }

    public function setProvider(?string $provider): self
    {
        $provider = is_string($provider) ? trim($provider) : null;
        $this->provider = $provider === '' ? null : $provider;

        return $this;
    }

    public function setReferenceId(?string $referenceId): self
    {
        $referenceId = is_string($referenceId) ? trim($referenceId) : null;
        $this->referenceId = $referenceId === '' ? null : $referenceId;

        return $this;
    }

    public function event(callable $callback): mixed
    {
        $this->mode ??= 'event';
        $relay = $this->ensureRelayCaptured();

        return $this->deliveryService->executeEvent($relay, $callback);
    }

    public function dispatchAutoRoute(): self
    {
        return $this->handleAutoRoute('auto_route');
    }

    public function autoRouteImmediately(): self
    {
        return $this->handleAutoRoute('auto_route_immediate');
    }

    public function http(): RelayHttpClient
    {
        $this->mode ??= 'http';

        $headerRecorder = function (array $headers): void {
            $this->mergeHeaders($headers);
        };

        return $this->deliveryService->http(
            fn (): Relay => $this->ensureRelayCaptured(),
            $this->resolvedHeaders(),
            $headerRecorder
        );
    }

    public function dispatch(mixed $job): PendingDispatch
    {
        $this->mode ??= 'dispatch';
        $relay = $this->ensureRelayCaptured();

        return $this->deliveryService->dispatch($relay, $job);
    }

    /**
     * @param  array<int, mixed>  $jobs
     */
    public function dispatchChain(array $jobs): PendingChain
    {
        $this->mode ??= 'dispatch_chain';
        $relay = $this->ensureRelayCaptured();

        return $this->deliveryService->dispatchChain($relay, $jobs);
    }

    private function handleAutoRoute(string $mode): self
    {
        $this->resolvedMethod = null;
        $this->resolvedUrl = null;

        try {
            $routeResult = $this->router->resolve($this->buildRouteContext());
            $this->applyRouteResult($routeResult);
            $this->mode ??= $mode;
            $this->status = RelayStatus::QUEUED;
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
        $this->resolvedMethod = $route->method;
        $this->resolvedUrl = $route->url;

        $this->mergeLifecycleDefaults($route->lifecycle);
        $this->mergeHeaders($route->headers, false);
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

    private function applyRequest(Request $request): void
    {
        $this->request = $request;

        if ($this->payload === null) {
            $extracted = $this->payloadExtractor->extract($request);

            if ($extracted['status'] === null) {
                $this->payload = $extracted['payload'];
            }
        }

        $this->mergeHeaders($this->extractRequestHeaders($request), false);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function mergeHeaders(array $headers, bool $overrideExisting = true): void
    {
        if ($headers === []) {
            return;
        }

        foreach ($headers as $name => $value) {
            $normalizedValue = $this->normalizeHeaderValue($value);

            if ($normalizedValue === null) {
                continue;
            }

            $key = strtolower($name);

            if (! $overrideExisting && array_key_exists($key, $this->headers)) {
                continue;
            }

            $this->headers[$key] = [
                'name' => $this->normalizeHeaderName($name),
                'value' => $normalizedValue,
            ];
        }
    }

    /**
     * @return array<string, string>
     */
    private function resolvedHeaders(): array
    {
        if ($this->headers === []) {
            return [];
        }

        $resolved = [];

        foreach ($this->headers as $header) {
            $resolved[$header['name']] = $header['value'];
        }

        return $resolved;
    }

    private function normalizeHeaderName(string $header): string
    {
        $segments = preg_split('/[-_\s]+/', trim($header)) ?: [];
        $segments = array_filter($segments, static fn (string $segment): bool => $segment !== '');

        if ($segments === []) {
            return $header;
        }

        $segments = array_map(static fn (string $segment): string => ucfirst(strtolower($segment)), $segments);

        return implode('-', $segments);
    }

    private function normalizeHeaderValue(mixed $value): ?string
    {
        if (is_array($value)) {
            if ($value === []) {
                return null;
            }

            $value = end($value);
        }

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function extractRequestHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $normalizedValue = $this->normalizeHeaderValue($values);

            if ($normalizedValue === null) {
                continue;
            }

            $headers[$name] = $normalizedValue;
        }

        return $headers;
    }
}
