<?php

declare(strict_types=1);

namespace AtlasRelay\Console\Commands;

use AtlasRelay\Events\RelayRestored;
use AtlasRelay\Models\Relay;
use AtlasRelay\Models\RelayArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RestoreRelayCommand extends Command
{
    protected $signature = 'atlas-relay:relay:restore {id : Relay archive ID} {--delete : Delete the archive record after restoration}';

    protected $description = 'Restores a relay from the archive back into the live table for replay scenarios.';

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

        $attributes['status'] = 'queued';
        $attributes['failure_reason'] = null;
        $attributes['retry_at'] = null;
        $attributes['processing_started_at'] = null;
        $attributes['processing_finished_at'] = null;
        $attributes['completed_at'] = null;
        $attributes['failed_at'] = null;
        $attributes['cancelled_at'] = null;
        $attributes['last_attempt_duration_ms'] = null;
        $attributes['attempt_count'] = 0;
        $attributes['created_at'] = Carbon::now();
        $attributes['updated_at'] = Carbon::now();

        $relay = Relay::query()->create($attributes);

        if ($this->option('delete')) {
            $archive->delete();
        }

        event(new RelayRestored($relay));

        $this->info("Relay {$relay->id} restored from archive.");

        return self::SUCCESS;
    }
}
