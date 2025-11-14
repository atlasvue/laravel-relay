<?php

declare(strict_types=1);

namespace Atlas\Relay\Console\Commands;

use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Models\RelayArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Class RestoreRelayCommand
 *
 * Rehydrates an archived relay for inspection or downstream recovery scenarios.
 */
class RestoreRelayCommand extends Command
{
    protected $signature = 'atlas-relay:relay:restore {id : Relay archive ID} {--delete : Delete the archive record after restoration}';

    protected $description = 'Restores a relay from the archive back into the live table for inspection or further processing.';

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $archive = RelayArchive::query()->find($id);

        if (! $archive) {
            $this->error("Archived relay {$id} not found.");

            return self::FAILURE;
        }

        $attributes = $archive->getAttributes();
        unset($attributes['archived_at']);

        $attributes['status'] = RelayStatus::QUEUED;
        $attributes['failure_reason'] = null;
        $attributes['processing_at'] = null;
        $attributes['completed_at'] = null;
        $attributes['created_at'] = Carbon::now();
        $attributes['updated_at'] = Carbon::now();

        $relay = Relay::query()->create($attributes);

        if ($this->option('delete')) {
            $archive->delete();
        }

        $this->info("Relay {$relay->id} restored from archive.");

        return self::SUCCESS;
    }
}
