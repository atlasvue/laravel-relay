# Atlas Relay Installation

This guide provides the minimal, streamlined steps required to install and configure Atlas Relay in your Laravel application.

## Table of Contents
- [Install the Package](#install-the-package)
- [Publish Configuration](#publish-configuration)
- [Select a Database Connection](#select-a-database-connection)
- [Publish Migrations](#publish-migrations)
- [Run Migrations](#run-migrations)
- [Add Scheduled Commands](#add-scheduled-commands)
- [Usage Entry Point](#usage-entry-point)

## Install the Package
```bash
composer require atlas-php/relay
```

## Publish Configuration
Generate `config/atlas-relay.php` to configure payload limits, masked headers, archive settings, and table names.

```bash
php artisan vendor:publish --tag=atlas-relay-config
```

## Select a Database Connection
(Optional) Set a dedicated database connection for relay tables:

```dotenv
ATLAS_RELAY_DATABASE_CONNECTION=tenant
```

Or adjust the connection in `config/atlas-relay.php`.

## Publish Migrations
Relay tables must exist before use.

```bash
php artisan vendor:publish --tag=atlas-relay-migrations
```

## Run Migrations
```bash
php artisan migrate
```

## Add Scheduled Commands
For automated archiving and purging, add to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('atlas-relay:archive')->dailyAt('22:00');
Schedule::command('atlas-relay:purge-archives')->dailyAt('23:00');
```

## Usage Entry Point
Use the Relay facade to receive and process inbound webhooks:

```php
use Atlas\Relay\Facades\Relay;

Relay::request($request)
    ->guard(MyWebhookGuard::class)
    ->event(fn ($payload) => ...);
```

## Also See
- [Atlas Relay](./PRD/Atlas-Relay.md)
- [Receive Webhook Relay](./PRD/Receive-Webhook-Relay.md)
- [Send Webhook Relay](./PRD/Send-Webhook-Relay.md)
- [Archiving & Logging](./PRD/Archiving-and-Logging.md)
- [Example Usage](./PRD/Example-Usage.md)