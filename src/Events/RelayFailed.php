<?php

declare(strict_types=1);

namespace AtlasRelay\Events;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Models\Relay;

/**
 * Fired when a relay attempt fails.
 */
class RelayFailed
{
    public function __construct(
        public Relay $relay,
        public RelayFailure $failure,
        public ?int $durationMs = null
    ) {}
}
