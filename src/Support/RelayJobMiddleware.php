<?php

declare(strict_types=1);

namespace AtlasRelay\Support;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Exceptions\RelayJobFailedException;
use AtlasRelay\Models\Relay;
use AtlasRelay\Services\RelayLifecycleService;
use Closure;
use Illuminate\Contracts\Container\Container;

/**
 * Job middleware that updates relay lifecycle state on success or failure.
 */
class RelayJobMiddleware
{
    public function __construct(
        private readonly int $relayId,
        private readonly ?Container $container = null
    ) {}

    public function handle(object $job, Closure $next): void
    {
        $lifecycle = $this->container?->make(RelayLifecycleService::class)
            ?? app(RelayLifecycleService::class);

        $relay = $this->resolveRelay();
        $relay = $lifecycle->startAttempt($relay);

        RelayJobContext::set($relay);

        $startedAt = microtime(true);

        try {
            $next($job);
            $duration = $this->durationSince($startedAt);
            $lifecycle->markCompleted($relay, [], $duration);
        } catch (RelayJobFailedException $exception) {
            $duration = $this->durationSince($startedAt);
            $lifecycle->markFailed($relay, $exception->failure, $exception->attributes, $duration);
            RelayJobContext::clear();

            throw $exception;
        } catch (\Throwable $exception) {
            $duration = $this->durationSince($startedAt);
            $lifecycle->markFailed($relay, RelayFailure::EXCEPTION, [], $duration);
            RelayJobContext::clear();

            throw $exception;
        }

        RelayJobContext::clear();
    }

    private function resolveRelay(): Relay
    {
        return Relay::query()->findOrFail($this->relayId);
    }

    private function durationSince(float $startedAt): int
    {
        return (int) max(0, round((microtime(true) - $startedAt) * 1000));
    }
}
