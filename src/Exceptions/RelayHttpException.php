<?php

declare(strict_types=1);

namespace AtlasRelay\Exceptions;

use AtlasRelay\Enums\RelayFailure;
use RuntimeException;
use Throwable;

/**
 * Exception thrown when outbound HTTP delivery violates PRD constraints or transport safeguards.
 *
 * Defined by PRD: Outbound Delivery — HTTP Mode, HTTP Interception & Lifecycle Tracking.
 */
class RelayHttpException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?RelayFailure $failure = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function failure(): ?RelayFailure
    {
        return $this->failure;
    }
}
