<?php

declare(strict_types=1);

namespace AtlasRelay\Routing;

use AtlasRelay\Models\RelayRoute;

/**
 * Value object describing a resolved route and its delivery defaults.
 */
class RouteResult
{
    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $lifecycle
     * @param  array<string, string>  $parameters
     */
    public function __construct(
        public readonly ?int $id,
        public readonly ?string $identifier,
        public readonly string $type,
        public readonly string $destinationUrl,
        public readonly array $headers = [],
        public readonly array $lifecycle = [],
        public readonly array $parameters = []
    ) {}

    /**
     * @param  array<string, string>  $parameters
     */
    public static function fromModel(RelayRoute $route, array $parameters = []): self
    {
        return new self(
            id: (int) $route->getAttribute('id'),
            identifier: $route->getAttribute('identifier'),
            type: $route->getAttribute('type'),
            destinationUrl: $route->getAttribute('destination_url'),
            headers: $route->getAttribute('headers') ?? [],
            lifecycle: [
                'is_retry' => (bool) $route->getAttribute('is_retry'),
                'retry_seconds' => $route->getAttribute('retry_seconds'),
                'retry_max_attempts' => $route->getAttribute('retry_max_attempts'),
                'is_delay' => (bool) $route->getAttribute('is_delay'),
                'delay_seconds' => $route->getAttribute('delay_seconds'),
                'timeout_seconds' => $route->getAttribute('timeout_seconds'),
                'http_timeout_seconds' => $route->getAttribute('http_timeout_seconds'),
            ],
            parameters: $parameters
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'identifier' => $this->identifier,
            'type' => $this->type,
            'destination_url' => $this->destinationUrl,
            'headers' => $this->headers,
            'lifecycle' => $this->lifecycle,
            'parameters' => $this->parameters,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? null,
            identifier: $data['identifier'] ?? null,
            type: $data['type'],
            destinationUrl: $data['destination_url'],
            headers: $data['headers'] ?? [],
            lifecycle: $data['lifecycle'] ?? [],
            parameters: $data['parameters'] ?? []
        );
    }
}
