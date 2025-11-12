<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Models\Relay;
use AtlasRelay\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

/**
 * Confirms package migrations expose the lifecycle schema and configurable table names required for relays and archives.
 *
 * Defined by PRD: Payload Capture — Data Model; Archiving & Logging — Data Model and Configuration.
 */
class MigrationRegistrationTest extends TestCase
{
    public function test_package_migrations_are_loadable(): void
    {
        $this->runPendingCommand('migrate', ['--database' => 'testbench'])->run();

        $relaysTable = config('atlas-relay.tables.relays');
        $routesTable = config('atlas-relay.tables.relay_routes');

        $expectedLifecycleColumns = [
            'request_source',
            'headers',
            'payload',
            'status',
            'mode',
            'route_id',
            'route_identifier',
            'destination_type',
            'destination_url',
            'failure_reason',
            'response_status',
            'response_payload',
            'response_payload_truncated',
            'is_retry',
            'retry_seconds',
            'retry_max_attempts',
            'attempt_count',
            'max_attempts',
            'is_delay',
            'delay_seconds',
            'timeout_seconds',
            'http_timeout_seconds',
            'last_attempt_duration_ms',
            'retry_at',
            'first_attempted_at',
            'last_attempted_at',
            'processing_started_at',
            'processing_finished_at',
            'completed_at',
            'failed_at',
            'cancelled_at',
            'archived_at',
            'meta',
        ];

        $this->assertTrue(Schema::hasColumns($relaysTable, $expectedLifecycleColumns));

        $this->assertTrue(Schema::hasColumns($routesTable, [
            'method',
            'path',
            'type',
            'destination_url',
            'is_retry',
            'retry_seconds',
            'retry_max_attempts',
            'is_delay',
            'delay_seconds',
            'timeout_seconds',
            'http_timeout_seconds',
        ]));

        $this->assertTrue(Schema::hasColumns(
            config('atlas-relay.tables.relay_archives'),
            array_merge($expectedLifecycleColumns, [
                'created_at',
                'updated_at',
            ])
        ));
    }

    public function test_table_names_can_be_configured_via_config_file(): void
    {
        config()->set('atlas-relay.tables', [
            'relays' => 'custom_relays',
            'relay_routes' => 'custom_relay_routes',
            'relay_archives' => 'custom_relay_archives',
        ]);

        $this->runPendingCommand('migrate:fresh', ['--database' => 'testbench'])->run();

        $this->assertTrue(Schema::hasTable('custom_relays'));
        $this->assertTrue(Schema::hasTable('custom_relay_routes'));
        $this->assertTrue(Schema::hasTable('custom_relay_archives'));

        $this->assertFalse(Schema::hasTable('atlas_relays'));
        $this->assertSame('custom_relays', (new Relay)->getTable());
    }
}
