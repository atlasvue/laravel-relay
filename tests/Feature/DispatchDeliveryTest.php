<?php

declare(strict_types=1);

namespace Atlas\Relay\Tests\Feature;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Exceptions\RelayJobFailedException;
use Atlas\Relay\Facades\Relay;
use Atlas\Relay\Support\RelayJobContext;
use Atlas\Relay\Support\RelayJobHelper;
use Atlas\Relay\Support\RelayJobMiddleware;
use Atlas\Relay\Tests\TestCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Queue;

/**
 * Ensures dispatch deliveries update lifecycle state, expose Laravel pending dispatch ergonomics, and surface failure helpers.
 *
 * Defined by PRD: Outbound Delivery — Dispatch Mode, Laravel-Native Wrappers, and Failure Reason Enum.
 */
class DispatchDeliveryTest extends TestCase
{
    public function test_dispatch_job_populates_context_and_marks_completion(): void
    {
        Queue::fake();

        $builder = Relay::payload(['foo' => 'bar']);
        $context = app(RelayJobContext::class);

        $builder->dispatch(new SuccessfulJob);

        $relay = $this->assertRelayInstance($builder->relay());

        $capturedJob = null;
        Queue::assertPushed(SuccessfulJob::class, function (SuccessfulJob $job) use (&$capturedJob): bool {
            $capturedJob = $job;

            return true;
        });

        $this->assertNotNull($capturedJob);

        $middleware = $capturedJob->middleware[0] ?? null;
        $this->assertInstanceOf(RelayJobMiddleware::class, $middleware);

        $this->assertNull($context->current());

        $middleware->handle($capturedJob, function (SuccessfulJob $job) use ($context): void {
            $this->assertNotNull($context->current());
            $job->handle();
        });

        $this->assertNull($context->current());

        $relay->refresh();
        $this->assertSame(RelayStatus::COMPLETED, $relay->status);
        $this->assertNull($relay->failure_reason);
    }

    public function test_job_helper_can_mark_failure_for_queued_job(): void
    {
        Queue::fake();

        $builder = Relay::payload(['foo' => 'bar']);

        $builder->dispatch(new FailingJob);

        $relay = $this->assertRelayInstance($builder->relay());

        $capturedJob = null;
        Queue::assertPushed(FailingJob::class, function (FailingJob $job) use (&$capturedJob): bool {
            $capturedJob = $job;

            return true;
        });

        $this->assertNotNull($capturedJob);

        $middleware = $capturedJob->middleware[0] ?? null;
        $this->assertInstanceOf(RelayJobMiddleware::class, $middleware);

        try {
            $middleware->handle($capturedJob, function (FailingJob $job): void {
                $job->handle();
            });
            $this->fail('Expected helper failure.');
        } catch (RelayJobFailedException) {
            $relay->refresh();
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

    public function test_job_helper_receives_context_for_queued_jobs(): void
    {
        Queue::fake();

        $builder = Relay::payload(['foo' => 'bar']);

        $builder->dispatch(new ContextAwareJob);

        $relay = $this->assertRelayInstance($builder->relay());

        $capturedJob = null;

        Queue::assertPushed(ContextAwareJob::class, function (ContextAwareJob $job) use (&$capturedJob): bool {
            $capturedJob = $job;

            return true;
        });

        $this->assertNotNull($capturedJob);

        $middleware = $capturedJob->middleware[0] ?? null;
        $this->assertInstanceOf(RelayJobMiddleware::class, $middleware);

        $middleware->handle($capturedJob, function (ContextAwareJob $job): void {
            $job->handle();
        });

        $this->assertSame($relay->id, $capturedJob->relayId);
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

/**
 * Queueable job used in tests to confirm the helper resolves the active relay context via the container.
 */
class ContextAwareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public ?int $relayId = null;

    public function handle(): void
    {
        $relay = app(RelayJobHelper::class)->relay();

        if ($relay !== null) {
            $this->relayId = $relay->id;
        }
    }
}
