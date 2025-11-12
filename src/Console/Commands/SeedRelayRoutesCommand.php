<?php

declare(strict_types=1);

namespace AtlasRelay\Console\Commands;

use AtlasRelay\Models\RelayRoute;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Class SeedRelayRoutesCommand
 *
 * Seeds auto-routing definitions from JSON, aligning with the route
 * configuration requirements in PRD — Routing (Route Definitions).
 */
class SeedRelayRoutesCommand extends Command
{
    protected $signature = 'atlas-relay:routes:seed {file : Path to a JSON file defining routes} {--replace : Truncate existing routes before seeding}';

    protected $description = 'Seed atlas relay routes from a JSON definition file.';

    public function handle(): int
    {
        $pathArgument = $this->argument('file');

        if (! is_string($pathArgument) || $pathArgument === '') {
            $this->error('The --file argument must be a valid string path.');

            return self::FAILURE;
        }

        $path = $pathArgument;

        if (! File::exists($path)) {
            $this->error("File {$path} not found.");

            return self::FAILURE;
        }

        $definitions = json_decode(File::get($path), true);

        if (! is_array($definitions)) {
            $this->error('Route seed file must contain a JSON array of route definitions.');

            return self::FAILURE;
        }

        if ($this->option('replace')) {
            RelayRoute::query()->delete();
        }

        $count = 0;

        foreach ($definitions as $definition) {
            RelayRoute::query()->create([
                'identifier' => $definition['identifier'] ?? null,
                'method' => strtoupper($definition['method'] ?? 'POST'),
                'path' => $definition['path'] ?? '/',
                'type' => $definition['type'] ?? 'http',
                'destination_url' => $definition['destination_url'] ?? $definition['destination'] ?? '',
                'headers' => $definition['headers'] ?? [],
                'is_retry' => $definition['is_retry'] ?? false,
                'retry_seconds' => $definition['retry_seconds'] ?? null,
                'retry_max_attempts' => $definition['retry_max_attempts'] ?? null,
                'is_delay' => $definition['is_delay'] ?? false,
                'delay_seconds' => $definition['delay_seconds'] ?? null,
                'timeout_seconds' => $definition['timeout_seconds'] ?? null,
                'http_timeout_seconds' => $definition['http_timeout_seconds'] ?? null,
                'enabled' => $definition['enabled'] ?? true,
            ]);

            $count++;
        }

        $this->info("Seeded {$count} routes.");

        return self::SUCCESS;
    }
}
