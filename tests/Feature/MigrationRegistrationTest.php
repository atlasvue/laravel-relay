<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Models\Relay;
use AtlasRelay\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class MigrationRegistrationTest extends TestCase
{
    public function test_package_migrations_are_loadable(): void
    {
        $this->artisan('migrate', ['--database' => 'testbench'])->run();

        $relaysTable = config('atlas-relay.tables.relays');
        $routesTable = config('atlas-relay.tables.relay_routes');

        $this->assertTrue(Schema::hasColumns($relaysTable, [
            'request_source',
            'headers',
            'payload',
            'status',
            'mode',
            'failure_reason',
            'response_status',
            'response_payload',
            'is_retry',
            'retry_seconds',
            'retry_max_attempts',
            'is_delay',
            'delay_seconds',
            'timeout_seconds',
            'http_timeout_seconds',
            'retry_at',
        ]));

        $this->assertTrue(Schema::hasColumns($routesTable, [
            'method',
            'path',
            'type',
            'destination',
            'is_retry',
            'retry_seconds',
            'retry_max_attempts',
            'is_delay',
            'delay_seconds',
            'timeout_seconds',
            'http_timeout_seconds',
        ]));

        $this->assertTrue(Schema::hasTable(config('atlas-relay.tables.relay_archives')));
    }

    public function test_table_names_can_be_configured_via_config_file(): void
    {
        config()->set('atlas-relay.tables', [
            'relays' => 'custom_relays',
            'relay_routes' => 'custom_relay_routes',
            'relay_archives' => 'custom_relay_archives',
        ]);

        $this->artisan('migrate:fresh', ['--database' => 'testbench'])->run();

        $this->assertTrue(Schema::hasTable('custom_relays'));
        $this->assertTrue(Schema::hasTable('custom_relay_routes'));
        $this->assertTrue(Schema::hasTable('custom_relay_archives'));

        $this->assertFalse(Schema::hasTable('atlas_relays'));
        $this->assertSame('custom_relays', (new Relay())->getTable());
    }
}
