<?php

declare(strict_types=1);

namespace AtlasRelay\Providers;

use AtlasRelay\Contracts\RelayManagerInterface;
use AtlasRelay\Models\Relay;
use AtlasRelay\RelayManager;
use AtlasRelay\Services\RelayCaptureService;
use AtlasRelay\Services\RelayLifecycleService;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the relay manager singleton and exposes package infrastructure.
 */
class AtlasRelayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/atlas-relay.php', 'atlas-relay');

        $this->app->singleton(RelayCaptureService::class, static fn (): RelayCaptureService => new RelayCaptureService(new Relay));
        $this->app->singleton(RelayLifecycleService::class, RelayLifecycleService::class);
        $this->app->singleton(RelayManagerInterface::class, RelayManager::class);
        $this->app->alias(RelayManagerInterface::class, 'atlas-relay.manager');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../database/migrations' => database_path('migrations'),
            ], 'atlas-relay-migrations');

            $this->publishes([
                __DIR__.'/../../config/atlas-relay.php' => config_path('atlas-relay.php'),
            ], 'atlas-relay-config');
        }
    }
}
