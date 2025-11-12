<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Facades\Relay;
use AtlasRelay\Models\RelayRoute;
use AtlasRelay\Routing\RouteContext;
use AtlasRelay\Routing\Router;
use AtlasRelay\Routing\RouteResult;
use AtlasRelay\Routing\RoutingException;
use AtlasRelay\Routing\RoutingProviderInterface;
use AtlasRelay\Tests\TestCase;
use Illuminate\Http\Request;

/**
 * Covers AutoRouting scenarios including default propagation, dynamic parameter capture, provider precedence, caching, and failure mapping.
 *
 * Defined by PRD: Auto Routing — AutoRouting Behavior, Programmatic Providers, Cache Behavior, and Failure Handling.
 */
class AutoRoutingTest extends TestCase
{
    public function test_dispatch_auto_route_applies_route_defaults_and_headers(): void
    {
        $route = $this->createRoute([
            'headers' => ['X-Route' => 'atlas'],
            'retry_seconds' => 90,
            'retry_max_attempts' => 4,
            'is_delay' => true,
            'delay_seconds' => 5,
        ]);

        $relay = $this->assertRelayInstance(
            Relay::request(Request::create('/orders', 'POST'))
                ->dispatchAutoRoute()
                ->relay()
        );

        $this->assertSame($route->id, $relay->route_id);
        $this->assertSame('auto_route', $relay->mode);
        $this->assertSame('http', $relay->destination_type);
        $this->assertTrue($relay->is_retry);
        $this->assertSame(90, $relay->retry_seconds);
        $this->assertTrue($relay->is_delay);
        $this->assertSame(5, $relay->delay_seconds);
        $meta = $relay->meta ?? [];
        $this->assertSame(['X-Route' => 'atlas'], $meta['route_headers'] ?? null);
    }

    public function test_dynamic_route_resolution_captures_parameters(): void
    {
        $this->createRoute([
            'path' => '/leads/{LEAD_ID:int}',
            'destination' => 'https://example.com/leads',
        ]);

        $relay = $this->assertRelayInstance(
            Relay::request(Request::create('/leads/42', 'POST'))
                ->dispatchAutoRoute()
                ->relay()
        );

        $meta = $relay->meta ?? [];
        $this->assertSame('42', $meta['route_parameters']['LEAD_ID'] ?? null);
    }

    public function test_programmatic_provider_precedence_and_caching_controls(): void
    {
        /** @var Router $router */
        $router = app(Router::class);
        $provider = new class implements RoutingProviderInterface
        {
            public string $destination = 'https://provider.test/one';

            public bool $shouldCache = true;

            public function determine(RouteContext $context): ?RouteResult
            {
                if ($context->normalizedPath() === null) {
                    return null;
                }

                return new RouteResult(
                    id: null,
                    identifier: 'provider',
                    type: 'http',
                    destination: $this->destination,
                    headers: ['X-Provider' => 'yes']
                );
            }

            public function cacheKey(RouteContext $context): ?string
            {
                return $this->shouldCache ? 'atlas-relay.provider' : null;
            }

            public function cacheTtlSeconds(): ?int
            {
                return $this->shouldCache ? 600 : null;
            }
        };

        $router->registerProvider('provider', $provider);

        $request = Request::create('/anything', 'POST');
        $relay = $this->assertRelayInstance(Relay::request($request)->dispatchAutoRoute()->relay());
        $this->assertNull($relay->route_id);
        $this->assertSame('https://provider.test/one', $relay->destination);

        $provider->destination = 'https://provider.test/two';
        $cachedRelay = $this->assertRelayInstance(Relay::request($request)->dispatchAutoRoute()->relay());
        $this->assertSame('https://provider.test/one', $cachedRelay->destination);

        $router->flushCache();
        $refreshedRelay = $this->assertRelayInstance(Relay::request($request)->dispatchAutoRoute()->relay());
        $this->assertSame('https://provider.test/two', $refreshedRelay->destination);
    }

    public function test_route_cache_is_invalidated_when_route_changes(): void
    {
        $route = $this->createRoute([
            'path' => '/cache-test',
            'destination' => 'https://example.com/one',
        ]);

        $request = Request::create('/cache-test', 'POST');
        $first = $this->assertRelayInstance(Relay::request($request)->dispatchAutoRoute()->relay());
        $this->assertSame('https://example.com/one', $first->destination);

        $route->destination = 'https://example.com/two';
        $route->save();

        $second = $this->assertRelayInstance(Relay::request($request)->dispatchAutoRoute()->relay());
        $this->assertSame('https://example.com/two', $second->destination);
    }

    public function test_disabled_route_sets_failure_reason(): void
    {
        $this->createRoute([
            'enabled' => false,
        ]);

        $relay = $this->assertRelayInstance(
            Relay::request(Request::create('/orders', 'POST'))
                ->dispatchAutoRoute()
                ->relay()
        );

        $this->assertSame('failed', $relay->status);
        $this->assertSame(RelayFailure::ROUTE_DISABLED->value, $relay->failure_reason);
        $meta = $relay->meta ?? [];
        $this->assertArrayHasKey('route', $meta['validation_errors'] ?? []);
    }

    public function test_no_route_match_sets_failure_reason(): void
    {
        $relay = $this->assertRelayInstance(
            Relay::request(Request::create('/missing', 'POST'))
                ->dispatchAutoRoute()
                ->relay()
        );

        $this->assertSame(RelayFailure::NO_ROUTE_MATCH->value, $relay->failure_reason);
    }

    public function test_resolver_exceptions_map_to_failure_reason(): void
    {
        /** @var Router $router */
        $router = app(Router::class);

        $router->registerProvider('failing', new class implements RoutingProviderInterface
        {
            public function determine(RouteContext $context): ?RouteResult
            {
                throw new \RuntimeException('Resolver boom');
            }

            public function cacheKey(RouteContext $context): ?string
            {
                return null;
            }

            public function cacheTtlSeconds(): ?int
            {
                return null;
            }
        });

        $relay = $this->assertRelayInstance(
            Relay::request(Request::create('/anything', 'POST'))
                ->dispatchAutoRoute()
                ->relay()
        );

        $this->assertSame(RelayFailure::ROUTE_RESOLVER_ERROR->value, $relay->failure_reason);
        $meta = $relay->meta ?? [];
        $this->assertSame('Resolver boom', $meta['validation_errors']['route'][0] ?? null);
    }

    public function test_router_resolver_errors_include_previous_exception(): void
    {
        $router = new Router(
            app(\Illuminate\Contracts\Cache\Repository::class),
            new RelayRoute()
        );

        $previous = new \RuntimeException('Resolver boom');

        $router->registerProvider('failing', new class($previous) implements RoutingProviderInterface
        {
            public function __construct(private readonly \Throwable $exception)
            {
            }

            public function determine(RouteContext $context): ?RouteResult
            {
                throw $this->exception;
            }

            public function cacheKey(RouteContext $context): ?string
            {
                return null;
            }

            public function cacheTtlSeconds(): ?int
            {
                return null;
            }
        });

        try {
            $router->resolve(new RouteContext('POST', '/anything'));
            self::fail('Expected RoutingException to be thrown.');
        } catch (RoutingException $exception) {
            $this->assertSame(RelayFailure::ROUTE_RESOLVER_ERROR, $exception->failure);
            $this->assertSame($previous, $exception->getPrevious());
            $this->assertSame('Resolver boom', $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createRoute(array $attributes = []): RelayRoute
    {
        $defaults = [
            'identifier' => 'orders',
            'method' => 'POST',
            'path' => '/orders',
            'type' => 'http',
            'destination' => 'https://example.com/orders',
            'headers' => [],
            'retry_policy' => null,
            'is_retry' => true,
            'retry_seconds' => 60,
            'retry_max_attempts' => 3,
            'is_delay' => false,
            'delay_seconds' => null,
            'timeout_seconds' => 30,
            'http_timeout_seconds' => 30,
            'enabled' => true,
        ];

        return RelayRoute::query()->create(array_merge($defaults, $attributes));
    }
}
