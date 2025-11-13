<?php

declare(strict_types=1);

namespace Atlas\Relay\Tests\Feature;

use Atlas\Relay\Enums\DestinationMethod;
use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Exceptions\RelayHttpException;
use Atlas\Relay\Facades\Relay;
use Atlas\Relay\Models\Relay as RelayModel;
use Atlas\Relay\Tests\TestCase;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as HttpClientResponse;
use Illuminate\Http\Request as HttpRequest;
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
        $this->assertSame(DestinationMethod::POST, $relay->destination_method);
        $this->assertSame(200, $relay->response_http_status);
        $this->assertSame(['ok' => true], $relay->response_payload);
    }

    public function test_http_entrypoint_tracks_payload_and_destination_without_manual_builder(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response(['ok' => true], 200),
        ]);

        Relay::http()->post('https://example.com/relay', ['payload' => true]);

        $relay = RelayModel::query()->latest('id')->first();

        $this->assertInstanceOf(RelayModel::class, $relay);
        $this->assertSame(['payload' => true], $relay->payload);
        $this->assertSame('https://example.com/relay', $relay->destination_url);
        $this->assertSame(DestinationMethod::POST, $relay->destination_method);
    }

    public function test_http_entrypoint_enforces_payload_limit(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $payload = ['data' => str_repeat('A', 70 * 1024)];
        $limit = (int) config('atlas-relay.capture.max_payload_bytes', 64 * 1024);

        try {
            Relay::http()->post('https://example.com/relay', $payload);
            $this->fail('Payload limit exception was not thrown.');
        } catch (RelayHttpException $exception) {
            $relay = RelayModel::query()->latest('id')->first();

            $this->assertInstanceOf(RelayModel::class, $relay);
            $this->assertSame(RelayFailure::PAYLOAD_TOO_LARGE->value, $relay->failure_reason);

            $this->assertSame(
                sprintf('Payload exceeds configured limit of %d bytes.', $limit),
                $exception->getMessage()
            );
        }
    }

    public function test_http_delivery_records_destination_url_before_transport(): void
    {
        $builder = Relay::payload(['status' => 'queued']);

        Http::fake([
            'https://example.com/*' => function (Request $request) use ($builder) {
                $relay = $this->assertRelayInstance($builder->relay());
                $relay->refresh();

                $this->assertSame('https://example.com/relay', $relay->destination_url);
                $this->assertSame(DestinationMethod::POST, $relay->destination_method);

                return Http::response(['ok' => true], 200);
            },
        ]);

        $builder->http()->post('https://example.com/relay');
    }

    public function test_http_delivery_applies_custom_headers(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $builder = Relay::payload(['status' => 'queued']);

        $builder->http()
            ->withHeaders([
                'X-API-KEY' => '1234567890',
                'X-TRACE' => 'relay-run',
            ])
            ->post('https://example.com/relay');

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-API-KEY', '1234567890')
                && $request->hasHeader('X-TRACE', 'relay-run');
        });
    }

    public function test_http_delivery_reuses_request_headers_when_present(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $incoming = HttpRequest::create('/relay', 'POST');
        $incoming->headers->set('Authorization', 'Bearer inbound-token');
        $incoming->headers->set('X-Request-Id', 'req-123');

        Relay::request($incoming)->http()->post('https://example.com/relay');

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Authorization', 'Bearer inbound-token')
                && $request->hasHeader('X-Request-Id', 'req-123');
        });
    }

    public function test_http_delivery_captures_custom_headers_on_relay_record(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $builder = Relay::payload(['status' => 'queued']);

        $builder->http()
            ->withHeaders([
                'Authorization' => 'Bearer outbound-token',
                'X-Trace' => 'relay-run',
            ])
            ->post('https://example.com/relay');

        $relay = $this->assertRelayInstance($builder->relay());
        $headers = $relay->headers ?? [];

        $this->assertSame('***', $headers['authorization'] ?? null);
        $this->assertSame('relay-run', $headers['x-trace'] ?? null);
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

    public function test_http_requires_https_targets_marks_failure(): void
    {
        $builder = Relay::payload(['status' => 'queued']);

        try {
            $builder->http()->post('http://insecure.test');
            $this->fail('RelayHttpException should have been thrown.');
        } catch (RelayHttpException $exception) {
            $this->assertSame('Atlas Relay HTTP deliveries require HTTPS targets.', $exception->getMessage());
        }

        $relay = $this->assertRelayInstance($builder->relay());
        $this->assertSame(RelayStatus::FAILED, $relay->status);
        $this->assertSame(RelayFailure::OUTBOUND_HTTP_ERROR->value, $relay->failure_reason);
        $this->assertNull($relay->response_http_status);
        $this->assertSame('Atlas Relay HTTP deliveries require HTTPS targets.', $relay->response_payload);
    }

    public function test_http_missing_url_marks_failure(): void
    {
        $builder = Relay::payload(['status' => 'queued']);

        try {
            /**
             * This call intentionally omits the required URL argument to verify guard behavior.
             *
             * @phpstan-ignore-next-line arguments.count
             */
            $builder->http()->post();
            $this->fail('RelayHttpException should have been thrown.');
        } catch (RelayHttpException $exception) {
            $this->assertSame('HTTP relay calls require a target URL.', $exception->getMessage());
        }

        $relay = $this->assertRelayInstance($builder->relay());
        $this->assertSame(RelayStatus::FAILED, $relay->status);
        $this->assertSame(RelayFailure::OUTBOUND_HTTP_ERROR->value, $relay->failure_reason);
        $this->assertNull($relay->response_http_status);
        $this->assertSame('HTTP relay calls require a target URL.', $relay->response_payload);
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
        $relay = $this->assertRelayInstance($builder->capture());

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
