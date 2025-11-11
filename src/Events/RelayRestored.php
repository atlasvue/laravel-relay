<?php

declare(strict_types=1);

namespace AtlasRelay\Events;

use AtlasRelay\Models\Relay;

/**
 * Fired when a relay is restored from the archive.
 */
class RelayRestored
{
    public function __construct(public Relay $relay) {}
}
