<?php

declare(strict_types=1);

namespace AtlasRelay\Console\Commands;

use AtlasRelay\Models\Relay;
use AtlasRelay\Models\RelayArchive;
use Illuminate\Console\Command;

/**
 * Class InspectRelayCommand
 *
 * Outputs stored lifecycle data for live or archived relays to support the
 * auditing visibility mandated by PRD — Archiving & Logging (Observability).
 */
class InspectRelayCommand extends Command
{
    protected $signature = 'atlas-relay:relay:inspect {id : Relay ID} {--archived : Inspect the archive table}';

    protected $description = 'Output JSON state for a relay or archived relay.';

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $model = $this->option('archived') ? new RelayArchive : new Relay;
        $relay = $model->newQuery()->find($id);

        if (! $relay) {
            $this->error("Relay {$id} not found.");

            return self::FAILURE;
        }

        $this->line(json_encode($relay->toArray(), JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
