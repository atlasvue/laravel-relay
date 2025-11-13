<?php

declare(strict_types=1);

namespace Atlas\Relay\Services;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Exceptions\RelayJobFailedException;
use Atlas\Relay\Jobs\DispatchRelayEventJob;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Support\RelayHttpClient;
use Atlas\Relay\Support\RelayJobContext;
use Atlas\Relay\Support\RelayJobMiddleware;
use Atlas\Relay\Support\RelayPendingChain;
use Closure;
use Illuminate\Bus\ChainedBatch;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Foundation\Bus\PendingChain;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use JsonSerializable;
use LogicException;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use Traversable;

/**
 * Orchestrates outbound delivery modes (events, HTTP, dispatch) and lifecycle recording.
 *
 * Defined by PRD: Outbound Delivery — Dispatch Mode.
 */
class RelayDeliveryService
{
    public function __construct(
        private readonly RelayLifecycleService $lifecycle,
        private readonly RelayJobContext $context
    ) {}

    /**
     * Executes a synchronous event handler.
     */
    public function executeEvent(Relay $relay, callable $callback): mixed
    {
        $relay = $this->lifecycle->startAttempt($relay);
        $startedAt = microtime(true);

        try {
            $result = $this->invokeEventCallback($relay, $callback);
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

    public function dispatchEventAsync(Relay $relay, callable $callback): PendingDispatch
    {
        $job = new DispatchRelayEventJob(Closure::fromCallable($callback));
        $job->through([$this->makeRelayJobMiddleware($relay->id)]);

        return dispatch($job);
    }

    public function http(Relay $relay): RelayHttpClient
    {
        $pending = Http::withOptions([
            'allow_redirects' => [
                'max' => config('atlas-relay.http.max_redirects', 3),
                'track_redirects' => true,
            ],
        ]);

        return new RelayHttpClient($pending, $this->lifecycle, $relay);
    }

    public function dispatch(Relay $relay, mixed $job): PendingDispatch
    {
        $this->applyJobMiddleware($job, $relay);

        return dispatch($job);
    }

    public function dispatchSync(Relay $relay, mixed $job): mixed
    {
        $relay = $this->lifecycle->startAttempt($relay);
        $startedAt = microtime(true);
        $this->context->set($relay);

        try {
            $result = Bus::dispatchSync($job);
            $duration = $this->durationSince($startedAt);
            $this->lifecycle->markCompleted($relay, [], $duration);

            return $result;
        } catch (RelayJobFailedException $exception) {
            $duration = $this->durationSince($startedAt);
            $this->lifecycle->markFailed($relay, $exception->failure, $exception->attributes, $duration);
            throw $exception;
        } catch (\Throwable $exception) {
            $duration = $this->durationSince($startedAt);
            $this->lifecycle->markFailed($relay, RelayFailure::EXCEPTION, [], $duration);
            $this->lifecycle->recordExceptionResponse($relay, $exception);

            throw $exception;
        } finally {
            $this->context->clear();
        }
    }

    public function runQueuedEventCallback(callable $callback): mixed
    {
        $relay = $this->context->current();

        if ($relay === null) {
            throw new LogicException('Relay job context is unavailable for dispatched events.');
        }

        return $this->invokeEventCallback($relay, $callback);
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

    private function invokeEventCallback(Relay $relay, callable $callback): mixed
    {
        $arguments = $this->determineEventArguments($callback, $relay);

        $result = $callback(...$arguments);

        $this->recordEventResponse($relay, $result);

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

    private function recordEventResponse(Relay $relay, mixed $response): void
    {
        if ($response === null) {
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
}
