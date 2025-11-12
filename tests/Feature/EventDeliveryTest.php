<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Exceptions\RelayJobFailedException;
use AtlasRelay\Facades\Relay;
use AtlasRelay\Jobs\DispatchRelayEventJob;
use AtlasRelay\Models\Relay as RelayModel;
use AtlasRelay\Services\RelayDeliveryService;
use AtlasRelay\Support\RelayJobMiddleware;
use AtlasRelay\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
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

        $this->assertSame('completed', $relay->status);
        $this->assertNull($relay->failure_reason);
    }

    public function test_event_completion_records_return_value(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);

        $builder->event(fn (): string => 'ok');

        $relay = $this->assertRelayInstance($builder->relay());

        $this->assertSame(['value' => 'ok'], $relay->response_payload);
        $this->assertNull($relay->response_status);
        $this->assertFalse($relay->response_payload_truncated);
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
            $this->assertSame('failed', $relay->status);
            $this->assertSame(RelayFailure::EXCEPTION->value, $relay->failure_reason);
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

        if (! $relayFromCallback instanceof RelayModel) {
            self::fail('Relay instance not captured during callback.');
        }
        $relay = $this->assertRelayInstance($builder->relay());
        $this->assertSame($relay->id, $relayFromCallback->id);
    }

    public function test_dispatch_event_pushes_job_and_marks_completion_after_execution(): void
    {
        Queue::fake();

        $builder = Relay::payload(['foo' => 'bar']);

        $builder->dispatchEvent(function (array $payload): void {
            self::assertSame(['foo' => 'bar'], $payload);
        });

        Queue::assertPushed(DispatchRelayEventJob::class);

        $relay = $this->assertRelayInstance($builder->relay());
        $this->assertSame('queued', $relay->status);

        $capturedJob = null;

        Queue::assertPushed(DispatchRelayEventJob::class, function (DispatchRelayEventJob $job) use (&$capturedJob): bool {
            $capturedJob = $job;

            return true;
        });

        $this->assertNotNull($capturedJob);

        $middleware = new RelayJobMiddleware($relay->id);
        /** @var RelayDeliveryService $service */
        $service = app(RelayDeliveryService::class);

        $middleware->handle($capturedJob, function (DispatchRelayEventJob $job) use ($service): void {
            $job->handle($service);
        });

        $relay->refresh();

        $this->assertSame('completed', $relay->status);
        $this->assertNull($relay->failure_reason);
    }

    public function test_dispatch_event_records_return_value_after_execution(): void
    {
        Queue::fake();

        $builder = Relay::payload(['foo' => 'bar']);

        $builder->dispatchEvent(fn (): string => 'queued-ok');

        $relay = $this->assertRelayInstance($builder->relay());

        $capturedJob = null;

        Queue::assertPushed(DispatchRelayEventJob::class, function (DispatchRelayEventJob $job) use (&$capturedJob): bool {
            $capturedJob = $job;

            return true;
        });

        $this->assertNotNull($capturedJob);

        $middleware = new RelayJobMiddleware($relay->id);
        /** @var RelayDeliveryService $service */
        $service = app(RelayDeliveryService::class);

        $middleware->handle($capturedJob, function (DispatchRelayEventJob $job) use ($service): void {
            $job->handle($service);
        });

        $relay->refresh();

        $this->assertSame(['value' => 'queued-ok'], $relay->response_payload);
        $this->assertNull($relay->response_status);
        $this->assertFalse($relay->response_payload_truncated);
    }

    public function test_dispatch_event_marks_failure_after_job_exception(): void
    {
        Queue::fake();

        $builder = Relay::payload(['foo' => 'bar']);

        $builder->dispatchEvent(function (): void {
            throw new RelayJobFailedException(RelayFailure::INVALID_PAYLOAD);
        });

        $relay = $this->assertRelayInstance($builder->relay());
        $this->assertSame('queued', $relay->status);

        $capturedJob = null;

        Queue::assertPushed(DispatchRelayEventJob::class, function (DispatchRelayEventJob $job) use (&$capturedJob): bool {
            $capturedJob = $job;

            return true;
        });

        $this->assertNotNull($capturedJob);

        $middleware = new RelayJobMiddleware($relay->id);
        /** @var RelayDeliveryService $service */
        $service = app(RelayDeliveryService::class);

        try {
            $middleware->handle($capturedJob, function (DispatchRelayEventJob $job) use ($service): void {
                $job->handle($service);
            });
            $this->fail('Expected RelayJobFailedException to be thrown.');
        } catch (RelayJobFailedException $exception) {
            $this->assertSame(RelayFailure::INVALID_PAYLOAD, $exception->failure);
        }

        $relay->refresh();

        $this->assertSame('failed', $relay->status);
        $this->assertSame(RelayFailure::INVALID_PAYLOAD->value, $relay->failure_reason);
    }
}
