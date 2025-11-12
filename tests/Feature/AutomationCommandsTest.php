<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Models\Relay;
use AtlasRelay\Models\RelayArchive;
use AtlasRelay\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Validates automation console commands for retrying, requeuing, timing out, archiving, and purging relays.
 *
 * Defined by PRD: Atlas Relay — Automation Jobs; Archiving & Logging — Archiving Process and Purge Process.
 */
class AutomationCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2025-01-15 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_retry_overdue_command_requeues_relays(): void
    {
        $relay = Relay::query()->create([
            'request_source' => 'cli',
            'headers' => [],
            'payload' => [],
            'status' => 'failed',
            'mode' => 'auto_route',
            'is_retry' => true,
            'retry_at' => Carbon::now()->subMinute(),
        ]);

        $this->runPendingCommand('atlas-relay:retry-overdue')->assertExitCode(0);

        $relay->refresh();
        $this->assertSame('queued', $relay->status);
        $this->assertNull($relay->retry_at);
        $this->assertNull($relay->failure_reason);
    }

    public function test_requeue_stuck_command_moves_processing_relays_back_to_queue(): void
    {
        $relay = Relay::query()->create([
            'request_source' => 'cli',
            'headers' => [],
            'payload' => [],
            'status' => 'processing',
            'mode' => 'event',
            'processing_started_at' => Carbon::now()->subMinutes(30),
        ]);

        $this->runPendingCommand('atlas-relay:requeue-stuck')->assertExitCode(0);

        $relay->refresh();
        $this->assertSame('queued', $relay->status);
        $this->assertNull($relay->processing_started_at);
    }

    public function test_enforce_timeouts_marks_relays_failed(): void
    {
        $relay = Relay::query()->create([
            'request_source' => 'cli',
            'headers' => [],
            'payload' => [],
            'status' => 'processing',
            'mode' => 'http',
            'timeout_seconds' => 60,
            'processing_started_at' => Carbon::now()->subMinutes(5),
        ]);

        $this->runPendingCommand('atlas-relay:enforce-timeouts')->assertExitCode(0);

        $relay->refresh();
        $this->assertSame('failed', $relay->status);
    }

    public function test_archive_and_purge_commands(): void
    {
        $relay = Relay::query()->create([
            'request_source' => 'cli',
            'headers' => [],
            'payload' => [],
            'status' => 'completed',
            'mode' => 'http',
            'updated_at' => Carbon::now()->subDays(60),
            'created_at' => Carbon::now()->subDays(61),
        ]);

        $this->runPendingCommand('atlas-relay:archive', ['--chunk' => 10])->assertExitCode(0);

        $this->assertDatabaseMissing($relay->getTable(), ['id' => $relay->id]);
        $this->assertDatabaseHas(RelayArchive::query()->getModel()->getTable(), ['id' => $relay->id]);

        RelayArchive::query()->update(['archived_at' => Carbon::now()->subDays(200)]);

        $this->runPendingCommand('atlas-relay:purge-archives')->assertExitCode(0);

        $this->assertDatabaseMissing(RelayArchive::query()->getModel()->getTable(), ['id' => $relay->id]);
    }

    public function test_archive_command_uses_configured_chunk_default_when_option_missing(): void
    {
        config(['atlas-relay.archiving.chunk_size' => 2]);

        foreach (range(1, 3) as $index) {
            Relay::query()->create([
                'request_source' => 'cli',
                'headers' => [],
                'payload' => [],
                'status' => 'completed',
                'mode' => 'http',
                'updated_at' => Carbon::now()->subDays(60)->subMinutes($index),
                'created_at' => Carbon::now()->subDays(61)->subMinutes($index),
            ]);
        }

        DB::connection()->enableQueryLog();

        $this->runPendingCommand('atlas-relay:archive')->assertExitCode(0);

        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertTrue(
            $queries->contains(fn (string $query): bool => str_contains(strtolower($query), 'limit 2')),
            'Expected archive command to query relays using configured chunk size of 2.'
        );
    }
}
