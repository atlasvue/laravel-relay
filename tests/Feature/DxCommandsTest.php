<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Models\Relay;
use AtlasRelay\Models\RelayArchive;
use AtlasRelay\Models\RelayRoute;
use AtlasRelay\Tests\TestCase;
use Illuminate\Support\Facades\File;

class DxCommandsTest extends TestCase
{
    public function test_route_seed_command_creates_routes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'routes');
        File::put($path, json_encode([
            [
                'identifier' => 'orders',
                'method' => 'POST',
                'path' => '/orders',
                'type' => 'http',
                'destination' => 'https://example.com/orders',
            ],
        ]));

        $this->artisan("atlas-relay:routes:seed {$path} --replace")
            ->assertExitCode(0);

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
            'status' => 'queued',
            'mode' => 'http',
        ]);

        $this->artisan("atlas-relay:relay:inspect {$relay->id}")
            ->assertExitCode(0)
            ->expectsOutputToContain('"status": "queued"');
    }

    public function test_restore_command_moves_archive_to_live(): void
    {
        $archive = RelayArchive::query()->create([
            'id' => 99,
            'request_source' => 'cli',
            'payload' => ['demo' => true],
            'headers' => [],
            'status' => 'completed',
            'mode' => 'http',
        ]);

        $this->artisan("atlas-relay:relay:restore {$archive->id} --delete")
            ->assertExitCode(0);

        $this->assertDatabaseHas((new Relay)->getTable(), ['id' => $archive->id, 'status' => 'queued']);
        $this->assertDatabaseMissing((new RelayArchive)->getTable(), ['id' => $archive->id]);
    }
}
