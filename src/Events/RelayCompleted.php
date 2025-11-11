<?php

declare(strict_types=1);

namespace AtlasRelay\Events;

use AtlasRelay\Models\Relay;

/**
 * Fired when a relay attempt finishes successfully.
 */
class RelayCompleted
{
    public function __construct(public Relay $relay, public ?int $durationMs = null) {}
}
