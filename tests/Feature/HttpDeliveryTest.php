<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Facades\Relay;
use AtlasRelay\Tests\TestCase;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use RuntimeException;

class HttpDeliveryTest extends TestCase
{
    public function test_http_delivery_records_response_and_completion(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $builder = Relay::payload(['status' => 'queued']);

        $response = $builder->http()->post('https://example.com/relay', ['payload' => true]);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://example.com/relay';
        });

        $this->assertTrue($response->successful());

        $relay = $builder->relay();
        $this->assertSame('completed', $relay?->status);
        $this->assertSame(200, $relay?->response_status);
        $this->assertSame(['ok' => true], $relay?->response_payload);
    }

    public function test_http_failure_records_failure_reason(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response('nope', 500),
        ]);

        $builder = Relay::payload(['status' => 'queued']);

        $response = $builder->http()->post('https://example.com/fail');

        $this->assertFalse($response->successful());

        $relay = $builder->relay();
        $this->assertSame('failed', $relay?->status);
        $this->assertSame(RelayFailure::OUTBOUND_HTTP_ERROR->value, $relay?->failure_reason);
    }

    public function test_http_requires_https_targets(): void
    {
        $builder = Relay::payload(['status' => 'queued']);

        $this->expectException(RuntimeException::class);

        $builder->http()->post('http://insecure.test');
    }

    public function test_http_connection_timeout_records_failure_reason(): void
    {
        Http::fake([
            'https://example.com/*' => function () {
                throw new ConnectionException('Connection timed out.');
            },
        ]);

        $builder = Relay::payload(['status' => 'queued']);

        try {
            $builder->http()->post('https://example.com/timeout');
            $this->fail('ConnectionException should have been thrown.');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
        }

        $relay = $builder->relay();
        $this->assertSame('failed', $relay?->status);
        $this->assertSame(RelayFailure::CONNECTION_TIMEOUT->value, $relay?->failure_reason);
    }

    public function test_http_redirect_host_change_records_failure_reason(): void
    {
        Http::fake([
            'https://example.com/*' => function (Request $request, array $options) {
                $psrResponse = new PsrResponse(302, ['Location' => 'https://evil.test/relay']);
                $transferStats = new TransferStats(
                    new PsrRequest('GET', 'https://evil.test/relay'),
                    $psrResponse,
                    null,
                    null,
                    [
                        'redirect_count' => 1,
                    ]
                );

                ($options['on_stats'])($transferStats);

                return Create::promiseFor($psrResponse);
            },
        ]);

        $builder = Relay::payload(['status' => 'queued']);

        try {
            $builder->http()->post('https://example.com/redirect');
            $this->fail('RuntimeException should have been thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Redirect attempted to a different host.', $exception->getMessage());
        }

        $relay = $builder->relay();
        $this->assertSame('failed', $relay?->status);
        $this->assertSame(RelayFailure::REDIRECT_HOST_CHANGED->value, $relay?->failure_reason);
    }

    public function test_http_redirect_count_exceeding_limit_records_failure_reason(): void
    {
        $builder = Relay::payload(['status' => 'queued']);
        $client = $builder->http();
        $relay = $builder->relay();

        $psrResponse = new PsrResponse(200, [], json_encode(['ok' => true]));
        $response = new HttpClientResponse($psrResponse);
        $response->transferStats = new TransferStats(
            new PsrRequest('GET', 'https://example.com/final'),
            $psrResponse,
            null,
            null,
            [
                'redirect_count' => config('atlas-relay.http.max_redirects', 3) + 2,
            ]
        );

        $method = new ReflectionMethod($client, 'evaluateRedirects');
        $method->setAccessible(true);

        try {
            $method->invoke($client, 'https://example.com/start', $response, $relay, 25);
            $this->fail('RuntimeException should have been thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Redirect limit exceeded for relay HTTP delivery.', $exception->getMessage());
        }

        $relay = $builder->relay();
        $this->assertSame('failed', $relay?->status);
        $this->assertSame(RelayFailure::TOO_MANY_REDIRECTS->value, $relay?->failure_reason);
    }
}
