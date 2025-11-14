<?php

declare(strict_types=1);

namespace Atlas\Relay\Tests\Feature;

use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Enums\RelayType;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Models\RelayArchive;
use Atlas\Relay\Tests\TestCase;

/**
 * Exercises developer tooling commands for inspecting relays and restoring archives.
 *
 * Defined by PRD: Archiving & Logging — Archiving Process and Notes on restoration.
 */
class DxCommandsTest extends TestCase
{
    public function test_inspect_command_outputs_relay_state(): void
    {
        $relay = Relay::query()->create([
            'source_ip' => '127.0.0.1',
            'payload' => ['demo' => true],
            'headers' => [],
            'status' => RelayStatus::QUEUED,
            'type' => RelayType::INBOUND,
        ]);

        $this->runPendingCommand('atlas-relay:relay:inspect', ['id' => $relay->id])
            ->assertExitCode(0)
            ->expectsOutputToContain('"status": 0');
    }

    public function test_restore_command_moves_archive_to_live(): void
    {
        $archive = RelayArchive::query()->create([
            'id' => 99,
            'source_ip' => '127.0.0.1',
            'payload' => ['demo' => true],
            'headers' => [],
            'status' => RelayStatus::COMPLETED,
            'type' => RelayType::INBOUND,
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
