<?php

declare(strict_types=1);

namespace Atlas\Relay\Services;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Jobs\RelayClosureJob;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Support\RelayHttpClient;
use Atlas\Relay\Support\RelayJobMiddleware;
use Atlas\Relay\Support\RelayPendingChain;
use Closure;
use Illuminate\Bus\ChainedBatch;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Foundation\Bus\PendingChain;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use JsonSerializable;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;
use Traversable;

/**
 * Orchestrates outbound delivery modes (events, HTTP, dispatch) and lifecycle recording.
 *
 * Defined by PRD: Send Webhook Relay — Dispatch Mode.
 */
class RelayDeliveryService
{
    public function __construct(
        private readonly RelayLifecycleService $lifecycle,
    ) {}

    /**
     * Executes a synchronous event handler.
     */
    public function executeEvent(Relay $relay, callable $callback, ?Request $request = null): mixed
    {
        $relay = $this->lifecycle->startAttempt($relay);
        $startedAt = microtime(true);

        try {
            $result = $this->invokeEventCallback($relay, $callback, $request);
            $duration = $this->durationSince($startedAt);
            $this->lifecycle->markCompleted($relay, [], $duration);

            return $result;
        } catch (\Throwable $exception) {
            $duration = $this->durationSince($startedAt);
            $this->lifecycle->markFailed($relay, RelayFailure::EXCEPTION, [], $duration);
            $this->lifecycle->recordExceptionResponse($relay, $exception);

            throw $exception;
        }
    }

    /**
     * @param  \Closure(): \Atlas\Relay\Models\Relay  $relayResolver
     * @param  array<string, string>  $headers
     * @param  \Closure(array<string, mixed>): void|null  $headerRecorder
     */
    public function http(Closure $relayResolver, array $headers = [], ?Closure $headerRecorder = null): RelayHttpClient
    {
        $pending = Http::withOptions([]);

        if ($headers !== []) {
            $pending = $pending->withHeaders($headers);
        }

        return new RelayHttpClient($pending, $this->lifecycle, $relayResolver, $headerRecorder);
    }

    public function dispatch(Relay $relay, mixed $job): PendingDispatch
    {
        if ($job instanceof Closure) {
            $job = RelayClosureJob::fromClosure($job, $relay->id);
        }

        $this->applyJobMiddleware($job, $relay);

        return dispatch($job);
    }

    /**
     * @param  array<int, mixed>  $jobs
     */
    public function dispatchChain(Relay $relay, array $jobs): PendingChain
    {
        $prepared = array_map(
            fn (mixed $job) => $this->prepareChainJob($job, $relay),
            $jobs
        );

        $collection = ChainedBatch::prepareNestedBatches(Collection::wrap($prepared));

        return new RelayPendingChain(
            $relay->id,
            $collection->shift(),
            $collection->toArray()
        );
    }

    private function prepareChainJob(mixed $job, Relay $relay): mixed
    {
        if ($job instanceof Collection) {
            return $job->map(fn (mixed $nested) => $this->prepareChainJob($nested, $relay));
        }

        if (is_array($job)) {
            return array_map(fn (mixed $nested) => $this->prepareChainJob($nested, $relay), $job);
        }

        $this->applyJobMiddleware($job, $relay);

        return $job;
    }

    private function makeRelayJobMiddleware(int $relayId): RelayJobMiddleware
    {
        return new RelayJobMiddleware($relayId);
    }

    private function applyJobMiddleware(mixed $job, Relay $relay): void
    {
        if (! is_object($job)) {
            return;
        }

        if (method_exists($job, 'through')) {
            $job->through([$this->makeRelayJobMiddleware($relay->id)]);

            return;
        }

        if (! method_exists($job, 'middleware')) {
            return;
        }

        $middleware = $job->middleware();

        if (! is_array($middleware)) {
            $middleware = is_iterable($middleware) ? iterator_to_array($middleware) : [];
        }

        $middleware[] = $this->makeRelayJobMiddleware($relay->id);

        if (property_exists($job, 'middleware')) {
            $job->middleware = $middleware;
        }
    }

    private function durationSince(float $startedAt): int
    {
        return (int) max(0, round((microtime(true) - $startedAt) * 1000));
    }

    private function invokeEventCallback(Relay $relay, callable $callback, ?Request $request = null): mixed
    {
        $arguments = $this->determineEventArguments($callback, $relay);

        $result = $callback(...$arguments);

        $this->recordEventResponse($relay, $result, $request);

        return $result;
    }

    /**
     * @return array<int, mixed>
     */
    private function determineEventArguments(callable $callback, Relay $relay): array
    {
        $reflection = $this->reflectCallback($callback);

        if ($reflection === null) {
            return [$relay->payload, $relay];
        }

        $parameters = $reflection->getParameters();

        if ($parameters === []) {
            return [];
        }

        $available = [$relay->payload, $relay];
        $arguments = [];

        foreach ($parameters as $index => $parameter) {
            if ($parameter->isVariadic()) {
                $arguments = array_merge($arguments, $available);

                break;
            }

            if ($index < count($available)) {
                $arguments[] = $available[$index];
            }
        }

        return $arguments;
    }

    private function reflectCallback(callable $callback): ?ReflectionFunctionAbstract
    {
        try {
            if ($callback instanceof \Closure) {
                return new ReflectionFunction($callback);
            }

            if (is_array($callback) && isset($callback[0], $callback[1])) {
                return new ReflectionMethod($callback[0], (string) $callback[1]);
            }

            if (is_object($callback) && method_exists($callback, '__invoke')) {
                return new ReflectionMethod($callback, '__invoke');
            }

            if (is_string($callback)) {
                return str_contains($callback, '::')
                    ? new ReflectionMethod($callback)
                    : new ReflectionFunction($callback);
            }
        } catch (ReflectionException) {
            return null;
        }

        return null;
    }

    private function recordEventResponse(Relay $relay, mixed $response, ?Request $request = null): void
    {
        if ($response === null) {
            return;
        }

        if ($response instanceof Responsable) {
            $response = $response->toResponse($this->requestForResponsable($request));
        }

        if ($response instanceof SymfonyResponse) {
            $payload = $this->normalizeSymfonyResponsePayload($response);
            $this->lifecycle->recordResponse($relay, $response->getStatusCode(), $payload);

            return;
        }

        $normalized = $this->normalizeEventResponse($response);

        $this->lifecycle->recordResponse($relay, null, $normalized);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function normalizeEventResponse(mixed $response): array
    {
        if ($response instanceof Arrayable) {
            $response = $response->toArray();
        } elseif ($response instanceof JsonSerializable) {
            $response = $response->jsonSerialize();
        } elseif ($response instanceof Traversable) {
            $response = iterator_to_array($response);
        } elseif (is_object($response) && method_exists($response, 'toArray')) {
            $converted = $response->toArray();

            if (is_array($converted)) {
                $response = $converted;
            }
        }

        if (is_array($response)) {
            return $response;
        }

        return ['value' => $response];
    }

    private function requestForResponsable(?Request $request): Request
    {
        if ($request instanceof Request) {
            return $request;
        }

        $resolved = function_exists('request') ? request() : null;

        if ($resolved instanceof Request) {
            return $resolved;
        }

        return Request::create('/', 'GET');
    }

    private function normalizeSymfonyResponsePayload(SymfonyResponse $response): mixed
    {
        $content = $this->responseContent($response);

        if ($content === null) {
            return null;
        }

        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        return $this->truncateResponseString($content);
    }

    private function responseContent(SymfonyResponse $response): ?string
    {
        try {
            $content = $response->getContent();
        } catch (Throwable) {
            return null;
        }

        if ($content === '' || $content === false) {
            return null;
        }

        return (string) $content;
    }

    private function truncateResponseString(string $content): string
    {
        $maxBytes = (int) config('atlas-relay.payload_max_bytes', 64 * 1024);

        if ($maxBytes <= 0) {
            return '';
        }

        return strlen($content) > $maxBytes
            ? substr($content, 0, $maxBytes)
            : $content;
    }
}
