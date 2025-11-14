<?php

declare(strict_types=1);

namespace Atlas\Relay;

use Atlas\Relay\Enums\HttpMethod;
use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Exceptions\ForbiddenWebhookException;
use Atlas\Relay\Exceptions\InvalidWebhookPayloadException;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Routing\RouteContext as RoutingContext;
use Atlas\Relay\Routing\Router;
use Atlas\Relay\Routing\RouteResult;
use Atlas\Relay\Routing\RoutingException;
use Atlas\Relay\Services\InboundGuardService;
use Atlas\Relay\Services\RelayCaptureService;
use Atlas\Relay\Services\RelayDeliveryService;
use Atlas\Relay\Services\RelayLifecycleService;
use Atlas\Relay\Support\InboundGuardProfile;
use Atlas\Relay\Support\RelayContext;
use Atlas\Relay\Support\RelayHttpClient;
use Atlas\Relay\Support\RequestPayloadExtractor;
use Illuminate\Foundation\Bus\PendingChain;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\Request;
use Throwable;

/**
 * Fluent builder that mirrors the relay lifecycle defined in the PRDs and persists relays via the capture service.
 */
class RelayBuilder
{
    private ?Request $request;

    private mixed $payload;

    private ?string $mode = null;

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

    private ?string $guardName = null;

    private bool $guardValidated = false;

    private bool $guardProfileResolved = false;

    private ?InboundGuardProfile $guardProfile = null;

    public function __construct(
        private readonly RelayCaptureService $captureService,
        private readonly Router $router,
        private readonly RelayDeliveryService $deliveryService,
        private readonly RelayLifecycleService $lifecycleService,
        private readonly InboundGuardService $guardService,
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
        $this->ensureInboundGuardValidated();

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

    public function provider(?string $provider): self
    {
        $provider = is_string($provider) ? trim($provider) : null;
        $this->provider = $provider === '' ? null : $provider;
        $this->resetGuardState();

        return $this;
    }

    public function setReferenceId(?string $referenceId): self
    {
        $referenceId = is_string($referenceId) ? trim($referenceId) : null;
        $this->referenceId = $referenceId === '' ? null : $referenceId;

        return $this;
    }

    public function guard(?string $guard): self
    {
        $guard = is_string($guard) ? trim($guard) : null;
        $this->guardName = $guard === '' ? null : $guard;
        $this->resetGuardState();

        return $this;
    }

    public function event(callable $callback): mixed
    {
        $this->ensureInboundGuardValidated();
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
        $this->ensureInboundGuardValidated();
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
        $this->ensureInboundGuardValidated();
        $this->mode ??= 'dispatch';
        $relay = $this->ensureRelayCaptured();

        return $this->deliveryService->dispatch($relay, $job);
    }

    /**
     * @param  array<int, mixed>  $jobs
     */
    public function dispatchChain(array $jobs): PendingChain
    {
        $this->ensureInboundGuardValidated();
        $this->mode ??= 'dispatch_chain';
        $relay = $this->ensureRelayCaptured();

        return $this->deliveryService->dispatchChain($relay, $jobs);
    }

    private function handleAutoRoute(string $mode): self
    {
        $this->ensureInboundGuardValidated();
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

        $this->mergeHeaders($route->headers, false);
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

    private function ensureInboundGuardValidated(): void
    {
        if ($this->guardValidated) {
            return;
        }

        $request = $this->request;

        if ($request === null) {
            $this->guardValidated = true;

            return;
        }

        $profile = $this->resolveGuardProfile();

        if ($profile === null) {
            $this->guardValidated = true;

            return;
        }

        $relay = $profile->captureForbidden ? $this->ensureRelayCaptured() : null;

        try {
            $this->guardService->validate($request, $profile, $relay);
        } catch (ForbiddenWebhookException|InvalidWebhookPayloadException $exception) {
            $this->handleGuardFailure($exception, $profile, $relay);

            throw $exception;
        }

        $this->guardValidated = true;
    }

    private function resolveGuardProfile(): ?InboundGuardProfile
    {
        if ($this->guardProfileResolved) {
            return $this->guardProfile;
        }

        $this->guardProfileResolved = true;

        if ($this->request === null) {
            $this->guardProfile = null;

            return null;
        }

        return $this->guardProfile = $this->guardService->resolveProfile($this->guardName, $this->provider);
    }

    private function resetGuardState(): void
    {
        $this->guardValidated = false;
        $this->guardProfileResolved = false;
        $this->guardProfile = null;
    }

    private function handleGuardFailure(
        Throwable $exception,
        InboundGuardProfile $profile,
        ?Relay $relay
    ): void {
        if (! $profile->captureForbidden || ! $relay instanceof Relay) {
            return;
        }

        $failure = $exception instanceof InvalidWebhookPayloadException
            ? RelayFailure::INVALID_PAYLOAD
            : RelayFailure::FORBIDDEN_GUARD;

        $status = $exception instanceof ForbiddenWebhookException
            ? $exception->statusCode()
            : ($exception instanceof InvalidWebhookPayloadException ? $exception->statusCode() : 400);

        $payload = [
            'guard' => $profile->name,
            'message' => $exception->getMessage(),
        ];

        if ($exception instanceof ForbiddenWebhookException) {
            $payload['violations'] = $exception->violations();
        }

        if ($exception instanceof InvalidWebhookPayloadException) {
            $payload['errors'] = $exception->violations();
        }

        $this->lifecycleService->markFailed(
            $relay,
            $failure,
            [
                'response_http_status' => $status,
                'response_payload' => $payload,
            ]
        );
    }

    private function applyRequest(Request $request): void
    {
        $this->request = $request;
        $this->resetGuardState();

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
