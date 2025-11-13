<?php

declare(strict_types=1);

namespace Atlas\Relay\Support;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Exceptions\RelayJobFailedException;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Services\RelayLifecycleService;
use Closure;

/**
 * Job middleware that updates relay lifecycle state on success or failure.
 *
 * Defined by PRD: Outbound Delivery — Dispatch Mode Middleware Lifecycle.
 */
class RelayJobMiddleware
{
    public function __construct(
        private readonly int $relayId
    ) {}

    public function handle(object $job, Closure $next): void
    {
        /** @var RelayLifecycleService $lifecycle */
        $lifecycle = app(RelayLifecycleService::class);
        /** @var RelayJobContext $context */
        $context = app(RelayJobContext::class);
        $relay = $this->resolveRelay();
        $relay = $lifecycle->startAttempt($relay);

        $context->set($relay);

        $startedAt = microtime(true);

        try {
            $next($job);
            $duration = $this->durationSince($startedAt);
            $lifecycle->markCompleted($relay, [], $duration);
        } catch (RelayJobFailedException $exception) {
            $duration = $this->durationSince($startedAt);
            $lifecycle->markFailed($relay, $exception->failure, $exception->attributes, $duration);
            $context->clear();

            throw $exception;
        } catch (\Throwable $exception) {
            $duration = $this->durationSince($startedAt);
            $lifecycle->markFailed($relay, RelayFailure::EXCEPTION, [], $duration);
            $lifecycle->recordExceptionResponse($relay, $exception);
            $context->clear();

            throw $exception;
        }

        $context->clear();
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
