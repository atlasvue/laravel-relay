<?php

declare(strict_types=1);

namespace Atlas\Relay\Tests\Feature;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Exceptions\RelayJobFailedException;
use Atlas\Relay\Facades\Relay;
use Atlas\Relay\Jobs\RelayClosureJob;
use Atlas\Relay\Models\Relay as RelayModel;
use Atlas\Relay\Support\RelayJobContext;
use Atlas\Relay\Support\RelayJobHelper;
use Atlas\Relay\Support\RelayJobMiddleware;
use Atlas\Relay\Tests\TestCase;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
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

    public function test_dispatch_job_status_transitions_success(): void
    {
        Queue::fake();
        StatusInspectionJob::$statusDuringHandle = null;

        $builder = Relay::payload(['foo' => 'bar']);

        $builder->dispatch(new StatusInspectionJob);

        $relay = $this->assertRelayInstance($builder->relay());

        $capturedJob = null;
        Queue::assertPushed(StatusInspectionJob::class, function (StatusInspectionJob $job) use (&$capturedJob): bool {
            $capturedJob = $job;

            return true;
        });

        $this->assertNotNull($capturedJob);

        $middleware = $capturedJob->middleware[0] ?? null;
        $this->assertInstanceOf(RelayJobMiddleware::class, $middleware);

        $middleware->handle($capturedJob, function (StatusInspectionJob $job): void {
            $job->handle();
        });

        $this->assertSame(RelayStatus::PROCESSING, StatusInspectionJob::$statusDuringHandle);

        $relay->refresh();
        $this->assertSame(RelayStatus::COMPLETED, $relay->status);
        $this->assertNull($relay->failure_reason);
    }

    public function test_dispatch_job_exception_marks_failed(): void
    {
        Queue::fake();

        $builder = Relay::payload(['foo' => 'bar']);

        $builder->dispatch(new ExplodingJob);

        $relay = $this->assertRelayInstance($builder->relay());

        $capturedJob = null;
        Queue::assertPushed(ExplodingJob::class, function (ExplodingJob $job) use (&$capturedJob): bool {
            $capturedJob = $job;

            return true;
        });

        $this->assertNotNull($capturedJob);

        $middleware = $capturedJob->middleware[0] ?? null;
        $this->assertInstanceOf(RelayJobMiddleware::class, $middleware);

        try {
            $middleware->handle($capturedJob, function (ExplodingJob $job): void {
                $job->handle();
            });
            $this->fail('Expected exception not thrown.');
        } catch (\RuntimeException) {
            $relay->refresh();
            $this->assertSame(RelayStatus::FAILED, $relay->status);
            $this->assertSame(RelayFailure::EXCEPTION->value, $relay->failure_reason);
        }
    }

    public function test_request_builder_dispatches_closure_with_payload(): void
    {
        Queue::fake();

        $payload = ['status' => 'queued'];
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

        $capturedPayload = null;
        $capturedRelayId = null;

        $builder = Relay::request($request);
        $builder->dispatch(function (array $incoming, RelayModel $relay) use (&$capturedPayload, &$capturedRelayId): void {
            $capturedPayload = $incoming;
            $capturedRelayId = $relay->id;
        });

        $relay = $this->assertRelayInstance($builder->relay());

        $queuedJob = null;
        Queue::assertPushed(RelayClosureJob::class, function (RelayClosureJob $job) use (&$queuedJob): bool {
            $queuedJob = $job;

            return true;
        });

        $this->assertNotNull($queuedJob);

        $middleware = $queuedJob->middleware[0] ?? null;
        $this->assertInstanceOf(RelayJobMiddleware::class, $middleware);

        $middleware->handle($queuedJob, function (RelayClosureJob $job): void {
            $job->handle();
        });

        $this->assertSame($payload, $capturedPayload);
        $this->assertSame($relay->id, $capturedRelayId);

        $relay->refresh();
        $this->assertSame(RelayStatus::COMPLETED, $relay->status);
        $this->assertNull($relay->failure_reason);
    }

    public function test_request_builder_dispatches_job_with_request_payload(): void
    {
        Queue::fake();

        PayloadAwareJob::$handledPayload = null;

        $payload = ['status' => 'queued', 'count' => 3];
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
        $builder->dispatch(new PayloadAwareJob);

        $relay = $this->assertRelayInstance($builder->relay());

        $queuedJob = null;
        Queue::assertPushed(PayloadAwareJob::class, function (PayloadAwareJob $job) use (&$queuedJob): bool {
            $queuedJob = $job;

            return true;
        });

        $this->assertNotNull($queuedJob);

        $middleware = $queuedJob->middleware[0] ?? null;
        $this->assertInstanceOf(RelayJobMiddleware::class, $middleware);

        $middleware->handle($queuedJob, function (PayloadAwareJob $job): void {
            $job->handle();
        });

        $this->assertSame($payload, PayloadAwareJob::$handledPayload);

        $relay->refresh();
        $this->assertSame(RelayStatus::COMPLETED, $relay->status);
        $this->assertNull($relay->failure_reason);
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

class PayloadAwareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var array<mixed>|null
     */
    public static $handledPayload;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [];
    }

    public function handle(): void
    {
        /** @var RelayJobContext $context */
        $context = app(RelayJobContext::class);
        $relay = $context->current();

        self::$handledPayload = $relay?->payload;
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

class StatusInspectionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public static ?RelayStatus $statusDuringHandle = null;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [];
    }

    public function handle(): void
    {
        /** @var RelayJobContext $context */
        $context = app(RelayJobContext::class);
        $relay = $context->current();
        self::$statusDuringHandle = $relay?->status;
    }
}

class ExplodingJob implements ShouldQueue
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
        throw new \RuntimeException('Job failure');
    }
}
