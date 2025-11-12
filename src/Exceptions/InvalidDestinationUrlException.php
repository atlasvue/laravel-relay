<?php

declare(strict_types=1);

namespace AtlasRelay\Exceptions;

use InvalidArgumentException;

/**
 * Exception thrown when a route result specifies a destination URL that exceeds storage constraints.
 *
 * Defined by PRD: Routing — Route Definitions (destination_url requirements).
 */
class InvalidDestinationUrlException extends InvalidArgumentException
{
    public static function exceedsMaxLength(int $length, int $maxLength = 255): self
    {
        return new self(sprintf(
            'Destination URL may not exceed %d characters; received %d characters.',
            $maxLength,
            $length
        ));
    }
}
