# Atlas Relay Installation

Follow the steps below to get Atlas Relay fully wired into your Laravel application. Each step mirrors the package defaults documented in the PRDs.

## 1. Require the Package

```bash
composer require atlas-php/relay
```

## 2. Publish the Configuration

Customize table names, capture constraints, or database connections via the published config file:

```bash
php artisan vendor:publish --tag=atlas-relay-config
```

This publishes `config/atlas-relay.php`. Update it (or use environment variables) to match your project requirements.

## 3. (Optional) Choose a Database Connection

Atlas Relay models default to your application’s primary connection. To target a different connection (e.g., tenant database), set the environment variable **before** running migrations:

```dotenv
ATLAS_RELAY_DATABASE_CONNECTION=tenant
```

You can achieve the same by editing `config/atlas-relay.php` after publishing the config.

## 4. Publish the Migrations

```bash
php artisan vendor:publish --tag=atlas-relay-migrations
```

This copies the relay tables into your application so you can review or modify them prior to running `migrate`.

## 5. Run the Migrations

```bash
php artisan migrate
```

Migrations will honor the connection configured in Step 3 (if provided).

## 6. Register the Scheduler

Atlas ships timed tasks for timeout enforcement, archiving, and purging. In Laravel 10+ add them to `routes/console.php` using the `Schedule` facade:

```php
<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('atlas-relay:enforce-timeouts')->hourly();
Schedule::command('atlas-relay:archive')->dailyAt('22:00');
Schedule::command('atlas-relay:purge-archives')->dailyAt('23:00');
```

Feel free to adjust the intervals (`everyTwoMinutes()`, `dailyAt('01:00')`, etc.) to match your workload; the commands themselves remain unchanged.
