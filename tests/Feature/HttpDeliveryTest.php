<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Enums\RelayStatus;
use AtlasRelay\Exceptions\RelayHttpException;
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

/**
 * Verifies HTTP deliveries enforce HTTPS, record responses, and translate transport conditions into relay failures.
 *
 * Defined by PRD: Outbound Delivery — HTTP Mode, HTTP Interception & Lifecycle Tracking, and Failure Reason Enum.
 */
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

        $relay = $this->assertRelayInstance($builder->relay());
        $this->assertSame(RelayStatus::COMPLETED, $relay->status);
        $this->assertSame(200, $relay->response_status);
        $this->assertSame(['ok' => true], $relay->response_payload);
    }

    public function test_http_failure_records_failure_reason(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response('nope', 500),
        ]);

        $builder = Relay::payload(['status' => 'queued']);

        $response = $builder->http()->post('https://example.com/fail');

        $this->assertFalse($response->successful());

        $relay = $this->assertRelayInstance($builder->relay());
        $this->assertSame(RelayStatus::FAILED, $relay->status);
        $this->assertSame(RelayFailure::OUTBOUND_HTTP_ERROR->value, $relay->failure_reason);
    }

    public function test_http_requires_https_targets(): void
    {
        $builder = Relay::payload(['status' => 'queued']);

        $this->expectException(RelayHttpException::class);

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

        $relay = $this->assertRelayInstance($builder->relay());
        $this->assertSame(RelayStatus::FAILED, $relay->status);
        $this->assertSame(RelayFailure::CONNECTION_TIMEOUT->value, $relay->failure_reason);
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
            $this->fail('RelayHttpException should have been thrown.');
        } catch (RelayHttpException $exception) {
            $this->assertSame('Redirect attempted to a different host.', $exception->getMessage());
        }

        $relay = $this->assertRelayInstance($builder->relay());
        $this->assertSame(RelayStatus::FAILED, $relay->status);
        $this->assertSame(RelayFailure::REDIRECT_HOST_CHANGED->value, $relay->failure_reason);
    }

    public function test_http_redirect_count_exceeding_limit_records_failure_reason(): void
    {
        $builder = Relay::payload(['status' => 'queued']);
        $client = $builder->http();
        $relay = $this->assertRelayInstance($builder->relay());

        $body = json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        $psrResponse = new PsrResponse(200, [], $body);
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

        $this->expectException(RelayHttpException::class);
        $this->expectExceptionMessage('Redirect limit exceeded for relay HTTP delivery.');

        try {
            $method->invoke($client, 'https://example.com/start', $response, $relay, 25);
        } finally {
            $relay = $this->assertRelayInstance($builder->relay());
            $this->assertSame(RelayStatus::FAILED, $relay->status);
            $this->assertSame(RelayFailure::TOO_MANY_REDIRECTS->value, $relay->failure_reason);
        }
    }
}
