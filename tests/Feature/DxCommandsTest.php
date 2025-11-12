<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Enums\RelayStatus;
use AtlasRelay\Models\Relay;
use AtlasRelay\Models\RelayArchive;
use AtlasRelay\Models\RelayRoute;
use AtlasRelay\Tests\TestCase;
use Illuminate\Support\Facades\File;

/**
 * Exercises developer tooling commands for seeding routes, inspecting relays, and restoring archives.
 *
 * Defined by PRD: Auto Routing — Route Definitions; Archiving & Logging — Archiving Process and Notes on restoration.
 */
class DxCommandsTest extends TestCase
{
    public function test_route_seed_command_creates_routes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'routes');
        if ($path === false) {
            self::fail('Unable to create temporary route definition file.');
        }

        $json = json_encode([
            [
                'identifier' => 'orders',
                'method' => 'POST',
                'path' => '/orders',
                'type' => 'http',
                'destination_url' => 'https://example.com/orders',
            ],
        ]);

        if ($json === false) {
            self::fail('Unable to encode route definitions to JSON.');
        }

        File::put($path, $json);

        $this->runPendingCommand('atlas-relay:routes:seed', [
            'file' => $path,
            '--replace' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas((new RelayRoute)->getTable(), [
            'identifier' => 'orders',
            'path' => '/orders',
        ]);
    }

    public function test_inspect_command_outputs_relay_state(): void
    {
        $relay = Relay::query()->create([
            'request_source' => 'cli',
            'payload' => ['demo' => true],
            'headers' => [],
            'status' => RelayStatus::QUEUED,
            'mode' => 'http',
        ]);

        $this->runPendingCommand('atlas-relay:relay:inspect', ['id' => $relay->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('"status": 0');
    }

    public function test_restore_command_moves_archive_to_live(): void
    {
        $archive = RelayArchive::query()->create([
            'id' => 99,
            'request_source' => 'cli',
            'payload' => ['demo' => true],
            'headers' => [],
            'status' => RelayStatus::COMPLETED,
            'mode' => 'http',
        ]);

        $this->runPendingCommand('atlas-relay:relay:restore', [
            'id' => $archive->id,
            '--delete' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas((new Relay)->getTable(), [
            'id' => $archive->id,
            'status' => RelayStatus::QUEUED->value,
        ]);
        $this->assertDatabaseMissing((new RelayArchive)->getTable(), ['id' => $archive->id]);
    }
}
