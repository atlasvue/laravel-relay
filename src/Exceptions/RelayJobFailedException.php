<?php

declare(strict_types=1);

namespace AtlasRelay\Exceptions;

use AtlasRelay\Enums\RelayFailure;
use RuntimeException;

/**
 * Exception thrown when a job explicitly signals a relay failure.
 */
class RelayJobFailedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly RelayFailure $failure,
        public readonly array $attributes = [],
        string $message = 'Relay job marked as failed.'
    ) {
        parent::__construct($message);
    }
}
