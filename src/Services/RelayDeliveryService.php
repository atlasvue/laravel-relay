<?php

declare(strict_types=1);

namespace AtlasRelay\Services;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Exceptions\RelayJobFailedException;
use AtlasRelay\Models\Relay;
use AtlasRelay\Support\RelayHttpClient;
use AtlasRelay\Support\RelayJobContext;
use AtlasRelay\Support\RelayJobMiddleware;
use Illuminate\Bus\PendingChain;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/**
 * Orchestrates outbound delivery modes (events, HTTP, dispatch) and lifecycle recording.
 */
class RelayDeliveryService
{
    public function __construct(
        private readonly RelayLifecycleService $lifecycle
    ) {}

    /**
     * Executes a synchronous event handler.
     */
    public function executeEvent(Relay $relay, callable $callback): mixed
    {
        $relay = $this->lifecycle->startAttempt($relay);
        $startedAt = microtime(true);

        try {
            $result = $callback();
            $duration = $this->durationSince($startedAt);
            $this->lifecycle->markCompleted($relay, [], $duration);

            return $result;
        } catch (\Throwable $exception) {
            $duration = $this->durationSince($startedAt);
            $this->lifecycle->markFailed($relay, RelayFailure::EXCEPTION, [], $duration);

            throw $exception;
        }
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
        RelayJobContext::set($relay);

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

            throw $exception;
        } finally {
            RelayJobContext::clear();
        }
    }

    public function dispatchChain(Relay $relay, array $jobs): PendingChain
    {
        foreach ($jobs as $job) {
            $this->applyJobMiddleware($job, $relay);
        }

        return Bus::chain($jobs);
    }

    private function applyJobMiddleware(mixed $job, Relay $relay): void
    {
        if (method_exists($job, 'through')) {
            $job->through([new RelayJobMiddleware($relay->id)]);

            return;
        }

        if (method_exists($job, 'middleware')) {
            $middleware = $job->middleware();
            $middleware[] = new RelayJobMiddleware($relay->id);

            if (property_exists($job, 'middleware')) {
                $job->middleware = $middleware;
            }
        }
    }

    private function durationSince(float $startedAt): int
    {
        return (int) max(0, round((microtime(true) - $startedAt) * 1000));
    }
}
