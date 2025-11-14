<?php

declare(strict_types=1);

namespace Atlas\Relay\Exceptions;

use InvalidArgumentException;

/**
 * Exception thrown when a destination URL exceeds storage constraints.
 *
 * Defined by PRD: Receive Webhook Relay — Destination URL requirements.
 */
class InvalidDestinationUrlException extends InvalidArgumentException
{
    public static function exceedsMaxLength(int $length, int $maxLength = 255): self
    {
        return new self(sprintf(
            'URL may not exceed %d characters; received %d characters.',
            $maxLength,
            $length
        ));
    }
}
