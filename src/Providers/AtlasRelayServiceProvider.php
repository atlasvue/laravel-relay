<?php

declare(strict_types=1);

namespace Atlas\Relay\Providers;

use Atlas\Relay\Console\Commands\ArchiveRelaysCommand;
use Atlas\Relay\Console\Commands\EnforceRelayTimeoutsCommand;
use Atlas\Relay\Console\Commands\InspectRelayCommand;
use Atlas\Relay\Console\Commands\PurgeRelayArchivesCommand;
use Atlas\Relay\Console\Commands\RestoreRelayCommand;
use Atlas\Relay\Contracts\RelayManagerInterface;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\RelayManager;
use Atlas\Relay\Services\InboundGuardService;
use Atlas\Relay\Services\RelayCaptureService;
use Atlas\Relay\Services\RelayDeliveryService;
use Atlas\Relay\Services\RelayLifecycleService;
use Atlas\Relay\Support\RelayJobContext;
use Atlas\Relay\Support\RelayJobHelper;
use Atlas\Relay\Support\RequestPayloadExtractor;
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

        $this->app->singleton(RequestPayloadExtractor::class, RequestPayloadExtractor::class);
        $this->app->singleton(RelayCaptureService::class, function ($app): RelayCaptureService {
            return new RelayCaptureService(new Relay, $app->make(RequestPayloadExtractor::class));
        });
        $this->app->singleton(RelayLifecycleService::class, RelayLifecycleService::class);
        $this->app->singleton(InboundGuardService::class, InboundGuardService::class);
        $this->app->scoped(RelayJobContext::class, RelayJobContext::class);
        $this->app->scoped(RelayDeliveryService::class, RelayDeliveryService::class);
        $this->app->scoped(RelayJobHelper::class, RelayJobHelper::class);
        $this->app->scoped(RelayManagerInterface::class, RelayManager::class);
        $this->app->alias(RelayManagerInterface::class, 'atlas-relay.manager');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                EnforceRelayTimeoutsCommand::class,
                ArchiveRelaysCommand::class,
                PurgeRelayArchivesCommand::class,
                RestoreRelayCommand::class,
                InspectRelayCommand::class,
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
