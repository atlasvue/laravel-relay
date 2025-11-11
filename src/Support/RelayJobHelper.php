<?php

declare(strict_types=1);

namespace AtlasRelay\Support;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Exceptions\RelayJobFailedException;
use AtlasRelay\Models\Relay;

/**
 * Helper available inside jobs via the container for interacting with the active relay context.
 */
class RelayJobHelper
{
    public function relay(): ?Relay
    {
        return RelayJobContext::current();
    }

    /**
     * Signal that the current job should fail with a specific reason.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws RelayJobFailedException
     */
    public function fail(RelayFailure $failure, string $message = '', array $attributes = []): void
    {
        throw new RelayJobFailedException($failure, $attributes, $message ?: 'Relay job failed.');
    }
}
