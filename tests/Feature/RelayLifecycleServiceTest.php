<?php

declare(strict_types=1);

namespace Atlas\Relay\Tests\Feature;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Facades\Relay;
use Atlas\Relay\Services\RelayLifecycleService;
use Atlas\Relay\Tests\TestCase;
use Carbon\Carbon;

/**
 * Ensures the lifecycle service manages status transitions for cancel, failure, and completion flows.
 */
class RelayLifecycleServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_cancel_flow_marks_relay_completed_with_failure_reason(): void
    {
        $relay = Relay::payload(['foo' => 'bar'])->capture();

        /** @var RelayLifecycleService $lifecycle */
        $lifecycle = app(RelayLifecycleService::class);

        $cancelled = $lifecycle->cancel($relay);

        $this->assertSame(RelayStatus::CANCELLED, $cancelled->status);
        $this->assertSame(RelayFailure::CANCELLED->value, $cancelled->failure_reason);
        $this->assertNotNull($cancelled->completed_at);
    }

    public function test_processing_and_completion_timestamps_are_managed(): void
    {
        Carbon::setTestNow('2025-04-01 08:00:00');

        $relay = Relay::payload(['foo' => 'bar'])->capture();

        /** @var RelayLifecycleService $lifecycle */
        $lifecycle = app(RelayLifecycleService::class);

        $lifecycle->startAttempt($relay);
        $relay->refresh();

        $this->assertSame(RelayStatus::PROCESSING, $relay->status);
        $this->assertTrue($relay->processing_at?->equalTo(Carbon::now()));
        $this->assertNull($relay->completed_at);

        Carbon::setTestNow('2025-04-01 08:05:00');
        $lifecycle->markFailed($relay, RelayFailure::ROUTE_TIMEOUT);
        $relay->refresh();

        $this->assertSame(RelayStatus::FAILED, $relay->status);
        $this->assertTrue($relay->completed_at?->equalTo(Carbon::now()));

        $relay->forceFill(['status' => RelayStatus::QUEUED])->save();

        Carbon::setTestNow('2025-04-01 08:06:00');
        $lifecycle->startAttempt($relay);
        $relay->refresh();

        $this->assertNull($relay->completed_at);

        Carbon::setTestNow('2025-04-01 08:07:00');
        $lifecycle->markCompleted($relay);
        $relay->refresh();

        $this->assertSame(RelayStatus::COMPLETED, $relay->status);
        $this->assertTrue($relay->completed_at?->equalTo(Carbon::now()));
    }
}
