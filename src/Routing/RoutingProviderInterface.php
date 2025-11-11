<?php

declare(strict_types=1);

namespace AtlasRelay\Routing;

/**
 * Defines the contract for programmatic route providers with optional caching controls.
 */
interface RoutingProviderInterface
{
    public function determine(RouteContext $context): ?RouteResult;

    /**
     * Returns the cache key for the resolved route, or null to disable provider caching.
     */
    public function cacheKey(RouteContext $context): ?string;

    /**
     * Returns the cache TTL in seconds for provider results, or null to use the global routing TTL.
     */
    public function cacheTtlSeconds(): ?int;
}
