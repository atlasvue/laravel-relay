# Atlas Relay Installation

Atlas Relay installs in minutes and gives you a consistent, reliable way to **receive, guard, and capture inbound webhooks** across your application.  
This guide focuses purely on setup—minimal steps, no noise.

---

## 1. Install the Package

```bash
composer require atlas-php/relay
```

---

## 2. Publish Configuration

This gives you `config/atlas-relay.php`, where you may adjust table names, payload limits, masked headers, and archive retention.

```bash
php artisan vendor:publish --tag=atlas-relay-config
```

---

## 3. (Optional) Select a Database Connection

If relays should use a specific connection (e.g., tenant database):

```dotenv
ATLAS_RELAY_DATABASE_CONNECTION=tenant
```

Or edit the published `config/atlas-relay.php`.

---

## 4. Publish Migrations

Relay tables must exist before use.

```bash
php artisan vendor:publish --tag=atlas-relay-migrations
```

This copies migrations into your project so you can review or customize them.

---

## 5. Run Migrations

```bash
php artisan migrate
```

---

## 6. Add Scheduled Commands

Atlas Relay handles archiving and purging automatically.  
Add these to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('atlas-relay:archive')->dailyAt('22:00');
Schedule::command('atlas-relay:purge-archives')->dailyAt('23:00');
```

You may adjust times to fit your workload.

---

## 7. You're Ready

Use the `Relay` facade from the package namespace:

```php
use Atlas\Relay\Facades\Relay;

Relay::request($request)
    ->guard(MyWebhookGuard::class)
    ->event(fn ($payload) => ...);
```

For more examples, see the Usage and PRD docs.
