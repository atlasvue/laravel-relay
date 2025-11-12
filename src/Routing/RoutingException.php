<?php

declare(strict_types=1);

namespace AtlasRelay\Routing;

use AtlasRelay\Enums\RelayFailure;
use RuntimeException;
use Throwable;

/**
 * Class RoutingException
 *
 * Represents routing resolution failures so relays can be marked according to
 * the rules in PRD — Routing (Failure Handling).
 */
class RoutingException extends RuntimeException
{
    public function __construct(public readonly RelayFailure $failure, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function noRoute(string $method, string $path): self
    {
        return new self(
            RelayFailure::NO_ROUTE_MATCH,
            sprintf('No route matched %s %s.', $method, $path)
        );
    }

    public static function disabledRoute(string $method, string $path): self
    {
        return new self(
            RelayFailure::ROUTE_DISABLED,
            sprintf('Route for %s %s is disabled.', $method, $path)
        );
    }

    public static function resolverError(string $message, Throwable $previous): self
    {
        return new self(RelayFailure::ROUTE_RESOLVER_ERROR, $message, $previous);
    }
}
