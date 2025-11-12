<?php

declare(strict_types=1);

namespace AtlasRelay\Support;

use Illuminate\Foundation\Bus\PendingChain;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * Pending chain wrapper that injects relay middleware when the chain is dispatched.
 *
 * Defined by PRD: Outbound Delivery — Dispatch Mode.
 */
class RelayPendingChain extends PendingChain
{
    public function __construct(
        private readonly int $relayId,
        mixed $job,
        array $chain
    ) {
        parent::__construct($job, $chain);
    }

    public function dispatch(): PendingDispatch
    {
        return parent::dispatch()->through(new RelayJobMiddleware($this->relayId));
    }

    public function dispatchIf($boolean): PendingDispatch|null
    {
        $result = parent::dispatchIf($boolean);

        if ($result instanceof PendingDispatch) {
            return $result->through(new RelayJobMiddleware($this->relayId));
        }

        return $result;
    }

    public function dispatchUnless($boolean): PendingDispatch|null
    {
        $result = parent::dispatchUnless($boolean);

        if ($result instanceof PendingDispatch) {
            return $result->through(new RelayJobMiddleware($this->relayId));
        }

        return $result;
    }
}
