<?php

declare(strict_types=1);

namespace Atlas\Relay\Tests\Feature;

use Atlas\Relay\Models\Relay;
use Atlas\Relay\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

/**
 * Confirms package migrations expose the lifecycle schema and configurable table names required for relays and archives.
 *
 * Defined by PRD: Receive Webhook Relay — Data Model; Archiving & Logging — Data Model and Configuration.
 */
class MigrationRegistrationTest extends TestCase
{
    public function test_package_migrations_are_loadable(): void
    {
        $this->runPendingCommand('migrate', ['--database' => 'testbench'])->run();

        $relaysTable = config('atlas-relay.tables.relays');
        $expectedLifecycleColumns = [
            'type',
            'status',
            'provider',
            'reference_id',
            'source_ip',
            'headers',
            'payload',
            'method',
            'url',
            'failure_reason',
            'response_http_status',
            'response_payload',
            'processing_at',
            'completed_at',
        ];

        $this->assertTrue(Schema::hasColumns($relaysTable, $expectedLifecycleColumns));

        $this->assertTrue(Schema::hasColumns(
            config('atlas-relay.tables.relay_archives'),
            array_merge($expectedLifecycleColumns, [
                'archived_at',
                'created_at',
                'updated_at',
            ])
        ));
    }

    public function test_table_names_can_be_configured_via_config_file(): void
    {
        config()->set('atlas-relay.tables', [
            'relays' => 'custom_relays',
            'relay_archives' => 'custom_relay_archives',
        ]);

        $this->runPendingCommand('migrate:fresh', ['--database' => 'testbench'])->run();

        $this->assertTrue(Schema::hasTable('custom_relays'));
        $this->assertTrue(Schema::hasTable('custom_relay_archives'));

        $this->assertFalse(Schema::hasTable('atlas_relays'));
        $this->assertSame('custom_relays', (new Relay)->getTable());
    }

    public function test_connection_can_be_configured_via_config_file(): void
    {
        config()->set('database.connections.relay_tenant', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        config()->set('atlas-relay.database.connection', 'relay_tenant');

        $this->runPendingCommand('migrate:fresh', ['--database' => 'testbench'])->run();

        $tenantSchema = Schema::connection('relay_tenant');

        $this->assertTrue($tenantSchema->hasTable('atlas_relays'));
        $this->assertTrue($tenantSchema->hasTable('atlas_relay_archives'));

        $this->assertFalse(Schema::hasTable('atlas_relays'));
        $this->assertSame('relay_tenant', (new Relay)->getConnectionName());
    }
}
