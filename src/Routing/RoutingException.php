<?php

declare(strict_types=1);

namespace AtlasRelay\Routing;

use AtlasRelay\Enums\RelayFailure;
use RuntimeException;

class RoutingException extends RuntimeException
{
    public function __construct(public readonly RelayFailure $failure, string $message)
    {
        parent::__construct($message);
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

    public static function resolverError(string $message): self
    {
        return new self(RelayFailure::ROUTE_RESOLVER_ERROR, $message);
    }
}
