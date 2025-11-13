<?php

declare(strict_types=1);

namespace Atlas\Relay\Support;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Exceptions\RelayJobFailedException;
use Atlas\Relay\Models\Relay;

/**
 * Helper available inside jobs via the container for interacting with the active relay context.
 *
 * Defined by PRD: Outbound Delivery — Dispatch Mode Context Helpers.
 */
class RelayJobHelper
{
    public function __construct(
        private readonly RelayJobContext $context
    ) {}

    public function relay(): ?Relay
    {
        return $this->context->current();
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
