<?php

declare(strict_types=1);

namespace Atlas\Relay\Tests;

use Atlas\Relay\Facades\Relay;
use Atlas\Relay\Models\Relay as RelayModel;
use Atlas\Relay\Providers\AtlasRelayServiceProvider;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Base TestCase bootstrapping Atlas Relay inside Orchestra Testbench so feature scenarios run against the package migrations and configuration.
 *
 * Defined by PRD: Receive Webhook Relay — Inbound Entry Point & Record Creation.
 *
 * @property \Illuminate\Foundation\Application $app
 */
abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->runPendingCommand('migrate', ['--database' => 'testbench'])->run();
    }

    protected function getPackageProviders($app): array
    {
        return [
            AtlasRelayServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Relay' => Relay::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function runPendingCommand(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        if (! $pending instanceof PendingCommand) {
            self::fail(sprintf('Artisan command "%s" did not return a pending command.', $command));
        }

        return $pending;
    }

    protected function assertRelayInstance(?RelayModel $relay): RelayModel
    {
        self::assertInstanceOf(RelayModel::class, $relay);

        return $relay;
    }
}
