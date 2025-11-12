<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Enums\RelayStatus;
use AtlasRelay\Exceptions\RelayJobFailedException;
use AtlasRelay\Facades\Relay;
use AtlasRelay\Support\RelayJobHelper;
use AtlasRelay\Tests\TestCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ensures dispatch deliveries update lifecycle state, expose Laravel pending dispatch ergonomics, and surface failure helpers.
 *
 * Defined by PRD: Outbound Delivery — Dispatch Mode, Laravel-Native Wrappers, and Failure Reason Enum.
 */
class DispatchDeliveryTest extends TestCase
{
    public function test_dispatch_sync_updates_relay_status(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);

        $builder->dispatchSync(new SuccessfulJob);

        $relay = $this->assertRelayInstance($builder->relay());
        $this->assertSame(RelayStatus::COMPLETED, $relay->status);
        $this->assertNull($relay->failure_reason);
    }

    public function test_job_helper_can_mark_failure(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);

        try {
            $builder->dispatchSync(new FailingJob);
            $this->fail('Expected helper failure.');
        } catch (RelayJobFailedException) {
            $relay = $this->assertRelayInstance($builder->relay());
            $this->assertSame(RelayStatus::FAILED, $relay->status);
            $this->assertSame(RelayFailure::CANCELLED->value, $relay->failure_reason);
        }
    }

    public function test_dispatch_returns_pending_dispatch(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);

        $pending = $builder->dispatch(new SuccessfulJob);

        $this->assertInstanceOf(\Illuminate\Foundation\Bus\PendingDispatch::class, $pending);
    }

    public function test_dispatch_executes_middleware_for_queued_jobs(): void
    {
        $builder = Relay::payload(['foo' => 'bar']);

        $builder->dispatch(new TypicalQueuedJob);

        $relay = $this->assertRelayInstance($builder->relay());
        $relay->refresh();

        $this->assertSame(1, $relay->attempt_count);
        $this->assertSame(RelayStatus::COMPLETED, $relay->status);
    }
}

class SuccessfulJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        // no-op
    }
}

class FailingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        app(RelayJobHelper::class)->fail(RelayFailure::CANCELLED, 'Manually failed');
    }
}

class TypicalQueuedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [];
    }

    public function handle(): void
    {
        // no-op
    }
}
