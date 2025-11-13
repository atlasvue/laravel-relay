<?php

declare(strict_types=1);

namespace Atlas\Relay\Support;

use Illuminate\Foundation\Bus\PendingChain;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * Pending chain wrapper that injects relay middleware when the chain is dispatched.
 *
 * Defined by PRD: Outbound Delivery — Dispatch Mode.
 */
class RelayPendingChain extends PendingChain
{
    /**
     * @param  array<int, mixed>  $chain
     */
    public function __construct(
        private readonly int $relayId,
        mixed $job,
        array $chain
    ) {
        parent::__construct($job, $chain);
    }

    public function dispatch(): PendingDispatch
    {
        $this->applyRelayMiddleware($this->job);

        return parent::dispatch(...func_get_args());
    }

    public function dispatchIf($boolean): ?PendingDispatch
    {
        $this->applyRelayMiddleware($this->job);

        return parent::dispatchIf($boolean);
    }

    public function dispatchUnless($boolean): ?PendingDispatch
    {
        $this->applyRelayMiddleware($this->job);

        return parent::dispatchUnless($boolean);
    }

    private function applyRelayMiddleware(mixed $job): void
    {
        if (! is_object($job)) {
            return;
        }

        if (method_exists($job, 'through')) {
            $job->through([$this->makeRelayJobMiddleware()]);

            return;
        }

        if (! method_exists($job, 'middleware')) {
            return;
        }

        $middleware = $job->middleware();

        if (! is_array($middleware)) {
            $middleware = is_iterable($middleware) ? iterator_to_array($middleware) : [];
        }

        $middleware[] = $this->makeRelayJobMiddleware();

        if (property_exists($job, 'middleware')) {
            $job->middleware = $middleware;
        }
    }

    private function makeRelayJobMiddleware(): RelayJobMiddleware
    {
        return new RelayJobMiddleware($this->relayId);
    }
}
