<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Facades\Relay;
use AtlasRelay\Models\Relay as RelayModel;
use AtlasRelay\Tests\TestCase;
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
        $relay = $builder->relay();

        $this->assertSame('completed', $relay?->status);
        $this->assertNull($relay?->failure_reason);
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
            $relay = $builder->relay();
            $this->assertSame('failed', $relay?->status);
            $this->assertSame(RelayFailure::EXCEPTION->value, $relay?->failure_reason);
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

    public function test_event_callback_can_access_relay_instance(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);
        $relayFromCallback = null;

        $builder->event(function (array $payload, RelayModel $relay) use (&$relayFromCallback): void {
            $relayFromCallback = $relay;

            $this->assertSame($payload, $relay->payload);
        });

        $this->assertInstanceOf(RelayModel::class, $relayFromCallback);
        $this->assertSame($builder->relay()?->id, $relayFromCallback?->id);
    }
}
