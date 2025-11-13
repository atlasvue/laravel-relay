<?php

declare(strict_types=1);

namespace Atlas\Relay\Support;

use Atlas\Relay\Models\Relay;

/**
 * Stores per-job relay context so jobs can introspect or signal failures without relying on static state.
 *
 * Defined by PRD: Outbound Delivery — Dispatch Mode Context Propagation.
 */
class RelayJobContext
{
    private ?Relay $current = null;

    public function set(Relay $relay): void
    {
        $this->current = $relay;
    }

    public function current(): ?Relay
    {
        return $this->current;
    }

    public function clear(): void
    {
        $this->current = null;
    }
}
