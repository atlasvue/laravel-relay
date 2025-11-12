<?php

declare(strict_types=1);

namespace AtlasRelay\Routing;

use AtlasRelay\Models\RelayRoute;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Arr;

/**
 * Resolves routes via programmatic providers or the atlas_relay_routes table with caching and invalidation.
 */
class Router
{
    /**
     * @var array<string, RoutingProviderInterface>
     */
    private array $providers = [];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly RelayRoute $routeModel,
        private readonly int $cacheTtlSeconds = 1200
    ) {}

    public function registerProvider(string $name, RoutingProviderInterface $provider): void
    {
        $this->providers[$name] = $provider;
    }

    public function flushCache(): void
    {
        $indexKey = $this->cacheIndexKey();
        $keys = $this->cache->get($indexKey, []);

        foreach ($keys as $key) {
            $this->cache->forget($key);
        }

        $this->cache->forget($indexKey);
    }

    public function resolve(RouteContext $context): RouteResult
    {
        $method = $context->normalizedMethod();
        $path = $context->normalizedPath();

        if (! $method || ! $path) {
            throw RoutingException::noRoute($method ?? 'UNKNOWN', $path ?? 'UNKNOWN');
        }

        if ($providerResult = $this->resolveViaProviders($context, $method, $path)) {
            return $providerResult;
        }

        if ($route = $this->resolveFromDatabase($context, $method, $path, true)) {
            return $route;
        }

        if ($this->resolveFromDatabase($context, $method, $path, false) !== null) {
            throw RoutingException::disabledRoute($method, $path);
        }

        throw RoutingException::noRoute($method, $path);
    }

    private function resolveViaProviders(RouteContext $context, string $method, string $path): ?RouteResult
    {
        foreach ($this->providers as $provider) {
            $cacheKey = $provider->cacheKey($context);

            if ($cacheKey && $cached = $this->cache->get($cacheKey)) {
                return RouteResult::fromArray($cached);
            }

            try {
                $result = $provider->determine($context);
            } catch (\Throwable $exception) {
                throw RoutingException::resolverError($exception->getMessage(), $exception);
            }

            if ($result === null) {
                continue;
            }

            if ($cacheKey) {
                $this->putCache($cacheKey, $result->toArray(), $provider->cacheTtlSeconds());
            }

            return $result;
        }

        return null;
    }

    private function resolveFromDatabase(RouteContext $context, string $method, string $path, bool $enabledOnly): ?RouteResult
    {
        $routes = $this->rememberRoutesForMethod($method, $enabledOnly);

        foreach ($routes['dynamic'] ?? [] as $route) {
            $parameters = $this->matchDynamicPath($route['path'], $path);

            if ($parameters === null) {
                continue;
            }

            return RouteResult::fromArray(array_merge(Arr::except($route, ['path']), [
                'parameters' => $parameters,
            ]));
        }

        $staticRoutes = $routes['static'] ?? [];

        if (isset($staticRoutes[$path])) {
            return RouteResult::fromArray($staticRoutes[$path]);
        }

        return null;
    }

    /**
     * @return array{
     *     static: array<string, array<string, mixed>>,
     *     dynamic: array<int, array<string, mixed>>
     * }
     */
    private function rememberRoutesForMethod(string $method, bool $enabledOnly): array
    {
        $cacheKey = $this->routesCacheKey($method, $enabledOnly);

        $routes = $this->cache->get($cacheKey);

        if ($routes !== null) {
            return $routes;
        }

        $query = $this->routeModel->newQuery()
            ->where('method', strtoupper($method));

        $query->where('enabled', $enabledOnly);

        $result = [
            'static' => [],
            'dynamic' => [],
        ];

        foreach ($query->get() as $route) {
            $payload = [
                'id' => (int) $route->getAttribute('id'),
                'identifier' => $route->getAttribute('identifier'),
                'type' => $route->getAttribute('type'),
                'destination' => $route->getAttribute('destination'),
                'headers' => $route->getAttribute('headers') ?? [],
                'lifecycle' => [
                    'is_retry' => (bool) $route->getAttribute('is_retry'),
                    'retry_seconds' => $route->getAttribute('retry_seconds'),
                    'retry_max_attempts' => $route->getAttribute('retry_max_attempts'),
                    'is_delay' => (bool) $route->getAttribute('is_delay'),
                    'delay_seconds' => $route->getAttribute('delay_seconds'),
                    'timeout_seconds' => $route->getAttribute('timeout_seconds'),
                    'http_timeout_seconds' => $route->getAttribute('http_timeout_seconds'),
                ],
                'path' => $route->getAttribute('path'),
            ];

            if (str_contains($payload['path'], '{')) {
                $result['dynamic'][] = $payload;

                continue;
            }

            $result['static'][$payload['path']] = Arr::except($payload, ['path']);
        }

        $this->putCache($cacheKey, $result);

        return $result;
    }

    /**
     * @return array<string, string>|null
     */
    private function matchDynamicPath(string $routePath, string $incomingPath): ?array
    {
        $segments = preg_split('/(\{[^\}]+\})/', $routePath, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        if ($segments === false) {
            return null;
        }

        $pattern = '';

        foreach ($segments as $segment) {
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                $segment = trim($segment, '{}');
                [$name, $type] = array_pad(explode(':', $segment, 2), 2, null);
                $pattern .= sprintf('(?P<%s>%s)', $name, $this->patternForType($type));

                continue;
            }

            $pattern .= preg_quote($segment, '#');
        }

        $regex = '#^'.$pattern.'$#';

        if (! preg_match($regex, $incomingPath, $matches)) {
            return null;
        }

        $parameters = [];

        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }

    private function patternForType(?string $type): string
    {
        return match ($type) {
            'int' => '\d+',
            'alpha' => '[A-Za-z]+',
            'alnum' => '[A-Za-z0-9]+',
            default => '[^/]+',
        };
    }

    private function routesCacheKey(string $method, bool $enabledOnly): string
    {
        return sprintf('atlas-relay.routing.routes.%s.%s', strtolower($method), $enabledOnly ? 'enabled' : 'disabled');
    }

    private function cacheIndexKey(): string
    {
        return 'atlas-relay.routing.cache-keys';
    }

    private function putCache(string $key, mixed $value, ?int $ttl = null): void
    {
        $seconds = $ttl ?? $this->cacheTtlSeconds;
        $this->cache->put($key, $value, $seconds);
        $this->storeCacheKey($key);
    }

    private function storeCacheKey(string $key): void
    {
        $indexKey = $this->cacheIndexKey();
        $keys = $this->cache->get($indexKey, []);

        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            $this->cache->forever($indexKey, $keys);
        }
    }
}
