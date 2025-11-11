<?php

declare(strict_types=1);

namespace AtlasRelay\Events;

use AtlasRelay\Models\Relay;

/**
 * Fired immediately after a relay record is created.
 */
class RelayCaptured
{
    public function __construct(public Relay $relay) {}
}
