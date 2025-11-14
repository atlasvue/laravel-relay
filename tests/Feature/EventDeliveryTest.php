<?php

declare(strict_types=1);

namespace Atlas\Relay\Tests\Feature;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Facades\Relay;
use Atlas\Relay\Models\Relay as RelayModel;
use Atlas\Relay\Tests\TestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Confirms synchronous event deliveries mark relays completed on success, capture payload context, and map exceptions to failure reasons.
 *
 * Defined by PRD: Send Webhook Relay — Event Mode and Failure Reason Enum.
 */
class EventDeliveryTest extends TestCase
{
    public function test_event_completion_updates_relay_status(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);

        $result = $builder->event(function (array $payload, RelayModel $relay): string {
            $relay->refresh();
            $this->assertSame(RelayStatus::PROCESSING, $relay->status);

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $relay = $this->assertRelayInstance($builder->relay());

        $this->assertSame(RelayStatus::COMPLETED, $relay->status);
        $this->assertNull($relay->failure_reason);
    }

    public function test_event_completion_records_return_value(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);

        $builder->event(fn (): string => 'ok');

        $relay = $this->assertRelayInstance($builder->relay());

        $this->assertSame(['value' => 'ok'], $relay->response_payload);
        $this->assertNull($relay->response_http_status);
    }

    public function test_event_completion_records_array_return_value(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);

        $builder->event(fn (): array => ['foo' => 'baz', 'count' => 3]);

        $relay = $this->assertRelayInstance($builder->relay());

        $this->assertSame(['foo' => 'baz', 'count' => 3], $relay->response_payload);
        $this->assertNull($relay->response_http_status);
    }

    public function test_event_failure_sets_failure_reason(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);

        try {
            $builder->event(function (array $payload, RelayModel $relay): void {
                $relay->refresh();
                $this->assertSame(RelayStatus::PROCESSING, $relay->status);

                throw new RuntimeException('boom');
            });
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException) {
            $relay = $this->assertRelayInstance($builder->relay());
            $this->assertSame(RelayStatus::FAILED, $relay->status);
            $this->assertSame(RelayFailure::EXCEPTION->value, $relay->failure_reason);
        }
    }

    public function test_event_failure_records_exception_payload(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);

        try {
            $builder->event(function (): void {
                throw new RuntimeException('boom');
            });
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException) {
            $relay = $this->assertRelayInstance($builder->relay());
            $this->assertIsString($relay->response_payload);
            $this->assertStringContainsString('RuntimeException', $relay->response_payload);
            $this->assertStringContainsString('boom', $relay->response_payload);
            $this->assertLessThanOrEqual(
                (int) config('atlas-relay.payload_max_bytes'),
                strlen($relay->response_payload)
            );
        }
    }

    public function test_event_exception_payload_is_truncated_to_limit(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);

        $original = config('atlas-relay.payload_max_bytes');
        config()->set('atlas-relay.payload_max_bytes', 32);

        try {
            $builder->event(function (): void {
                throw new RuntimeException(str_repeat('long-message-', 5));
            });
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException) {
            $relay = $this->assertRelayInstance($builder->relay());
            $this->assertIsString($relay->response_payload);
            $this->assertLessThanOrEqual(32, strlen($relay->response_payload));
            $this->assertStringEndsWith('...', $relay->response_payload);
        } finally {
            config()->set('atlas-relay.payload_max_bytes', $original);
        }
    }

    public function test_event_callback_can_access_payload_when_declared(): void
    {
        $builder = Relay::payload(['foo' => 'bar', 'count' => 5]);

        $result = $builder->event(function (array $payload): string {
            return sprintf('%s:%d', $payload['foo'], $payload['count']);
        });

        $this->assertSame('bar:5', $result);
    }

    public function test_request_seeded_event_receives_payload_automatically(): void
    {
        $payload = ['foo' => 'bar', 'count' => 5];
        $request = Request::create(
            '/relay',
            'POST',
            [],
            [],
            [],
            [],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
        $request->headers->set('Content-Type', 'application/json');

        $builder = Relay::request($request);
        $result = $builder->event(function (array $incoming): string {
            return sprintf('%s:%d', $incoming['foo'], $incoming['count']);
        });

        $this->assertSame('bar:5', $result);

        $relay = $this->assertRelayInstance($builder->relay());
        $this->assertSame($payload, $relay->payload);
    }

    public function test_event_can_return_http_response_from_facade_chain(): void
    {
        $payload = ['foo' => 'bar'];
        $request = Request::create(
            '/relay',
            'POST',
            [],
            [],
            [],
            [],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
        $request->headers->set('Content-Type', 'application/json');

        $response = Relay::request($request)->event(
            function (array $incoming): JsonResponse {
                return response()->json(['ok' => $incoming['foo']], 202);
            }
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(202, $response->getStatusCode());

        $relay = $this->assertRelayInstance(RelayModel::query()->first());
        $this->assertSame(202, $relay->response_http_status);
        $this->assertSame(['ok' => 'bar'], $relay->response_payload);
    }

    public function test_event_http_response_payload_is_truncated_to_limit(): void
    {
        $payload = ['foo' => 'bar'];
        $request = Request::create(
            '/relay',
            'POST',
            [],
            [],
            [],
            [],
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
        $request->headers->set('Content-Type', 'application/json');

        $builder = Relay::request($request);
        $limit = 32;
        $original = config('atlas-relay.payload_max_bytes');
        config()->set('atlas-relay.payload_max_bytes', $limit);

        $body = str_repeat('long-payload', 5);

        try {
            $builder->event(function () use ($body) {
                return response($body, 200);
            });
        } finally {
            config()->set('atlas-relay.payload_max_bytes', $original);
        }

        $relay = $this->assertRelayInstance($builder->relay());
        $this->assertIsString($relay->response_payload);
        $this->assertSame(substr($body, 0, $limit), $relay->response_payload);
        $this->assertSame(200, $relay->response_http_status);
    }

    public function test_event_callback_can_access_relay_instance(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);
        $relayFromCallback = null;

        $builder->event(function (array $payload, RelayModel $relay) use (&$relayFromCallback): void {
            $relayFromCallback = $relay;

            $this->assertSame($payload, $relay->payload);
        });

        if (! $relayFromCallback instanceof RelayModel) {
            self::fail('Relay instance not captured during callback.');
        }
        $relay = $this->assertRelayInstance($builder->relay());
        $this->assertSame($relay->id, $relayFromCallback->id);
    }
}
