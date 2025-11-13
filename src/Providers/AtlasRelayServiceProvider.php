<?php

declare(strict_types=1);

namespace Atlas\Relay\Providers;

use Atlas\Relay\Console\Commands\ArchiveRelaysCommand;
use Atlas\Relay\Console\Commands\EnforceRelayTimeoutsCommand;
use Atlas\Relay\Console\Commands\InspectRelayCommand;
use Atlas\Relay\Console\Commands\PurgeRelayArchivesCommand;
use Atlas\Relay\Console\Commands\RequeueStuckRelaysCommand;
use Atlas\Relay\Console\Commands\RestoreRelayCommand;
use Atlas\Relay\Console\Commands\RetryOverdueRelaysCommand;
use Atlas\Relay\Console\Commands\SeedRelayRoutesCommand;
use Atlas\Relay\Contracts\RelayManagerInterface;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Models\RelayRoute;
use Atlas\Relay\RelayManager;
use Atlas\Relay\Routing\Router;
use Atlas\Relay\Services\RelayCaptureService;
use Atlas\Relay\Services\RelayDeliveryService;
use Atlas\Relay\Services\RelayLifecycleService;
use Atlas\Relay\Support\RelayJobContext;
use Atlas\Relay\Support\RelayJobHelper;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Registers the relay manager singleton and exposes package infrastructure.
 */
class AtlasRelayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/atlas-relay.php', 'atlas-relay');

        $this->app->singleton(Router::class, function ($app): Router {
            $cacheFactory = $app->make(CacheFactory::class);
            $cacheStore = config('atlas-relay.routing.cache_store');
            $cacheRepository = $cacheStore ? $cacheFactory->store($cacheStore) : $cacheFactory->store();

            return new Router(
                $cacheRepository,
                new RelayRoute,
                (int) config('atlas-relay.routing.cache_ttl_seconds', 1200)
            );
        });

        $this->app->alias(Router::class, 'atlas-relay.router');

        $this->app->singleton(RelayCaptureService::class, static fn (): RelayCaptureService => new RelayCaptureService(new Relay));
        $this->app->singleton(RelayLifecycleService::class, RelayLifecycleService::class);
        $this->app->scoped(RelayJobContext::class, RelayJobContext::class);
        $this->app->scoped(RelayDeliveryService::class, RelayDeliveryService::class);
        $this->app->scoped(RelayJobHelper::class, RelayJobHelper::class);
        $this->app->scoped(RelayManagerInterface::class, RelayManager::class);
        $this->app->alias(RelayManagerInterface::class, 'atlas-relay.manager');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        RelayRoute::saved(function (): void {
            $this->app->make(Router::class)->flushCache();
        });

        RelayRoute::deleted(function (): void {
            $this->app->make(Router::class)->flushCache();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                RetryOverdueRelaysCommand::class,
                RequeueStuckRelaysCommand::class,
                EnforceRelayTimeoutsCommand::class,
                ArchiveRelaysCommand::class,
                PurgeRelayArchivesCommand::class,
                RestoreRelayCommand::class,
                InspectRelayCommand::class,
                SeedRelayRoutesCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../../database/migrations' => database_path('migrations'),
            ], 'atlas-relay-migrations');

            $this->publishes([
                __DIR__.'/../../config/atlas-relay.php' => config_path('atlas-relay.php'),
            ], 'atlas-relay-config');

            $this->notifyPendingInstallSteps();
        }
    }

    private function notifyPendingInstallSteps(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        $missingConfig = ! $this->configPublished();
        $missingMigrations = ! $this->migrationsPublished();

        if (! $missingConfig && ! $missingMigrations) {
            return;
        }

        $output = $this->consoleOutput();
        $output->writeln('');
        $output->writeln('<comment>[Atlas Relay]</comment> Finalize installation by publishing assets and running migrations:');

        if ($missingConfig) {
            $output->writeln('  php artisan vendor:publish --tag=atlas-relay-config');
        }

        if ($missingMigrations) {
            $output->writeln('  php artisan vendor:publish --tag=atlas-relay-migrations');
        }

        $output->writeln('  php artisan migrate');
        $output->writeln('');
    }

    private function consoleOutput(): ConsoleOutput
    {
        if ($this->app->bound(ConsoleOutput::class)) {
            return $this->app->make(ConsoleOutput::class);
        }

        return new ConsoleOutput;
    }

    private function configPublished(): bool
    {
        if (! function_exists('config_path')) {
            return false;
        }

        return file_exists(config_path('atlas-relay.php'));
    }

    private function migrationsPublished(): bool
    {
        if (! function_exists('database_path')) {
            return false;
        }

        $pattern = database_path('migrations/*atlas_relay*');
        $matches = glob($pattern);

        if ($matches === false) {
            return false;
        }

        return $matches !== [];
    }
}
