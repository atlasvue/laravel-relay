<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Facades\Relay;
use AtlasRelay\Tests\TestCase;
use RuntimeException;

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
}
