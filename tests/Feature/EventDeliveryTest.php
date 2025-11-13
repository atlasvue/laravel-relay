<?php

declare(strict_types=1);

namespace Atlas\Relay\Tests\Feature;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Facades\Relay;
use Atlas\Relay\Models\Relay as RelayModel;
use Atlas\Relay\Tests\TestCase;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Confirms synchronous event deliveries mark relays completed on success, capture payload context, and map exceptions to failure reasons.
 *
 * Defined by PRD: Outbound Delivery — Event Mode and Failure Reason Enum.
 */
class EventDeliveryTest extends TestCase
{
    public function test_event_completion_updates_relay_status(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);

        $result = $builder->event(fn (): string => 'ok');

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

    public function test_event_failure_sets_failure_reason(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);

        try {
            $builder->event(function (): void {
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
                (int) config('atlas-relay.lifecycle.exception_response_max_bytes'),
                strlen($relay->response_payload)
            );
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
