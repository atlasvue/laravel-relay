# Atlas Relay

> A unified Laravel relay system for **sending and receiving webhooks** — built for **reliability**, **observability**, and **control**. Capture, process, and relay any payload with full lifecycle visibility.

---

## 🌍 Overview

**Atlas Relay** is a Laravel package that provides a **complete relay system** for managing both **inbound and outbound webhooks**.

It unifies webhook reception, processing, routing, and delivery into one lifecycle — ensuring every payload is captured, tracked, and delivered with full transparency.

---

## 💡 Why Atlas Relay?

Webhook handling is notoriously fragile—plagued by missing retries, inconsistent logging, and scattered error handling; Atlas Relay eliminates these pain points with a durable, observable pipeline that guarantees delivery and traceability.

Atlas Relay ensures:

* Every webhook is **stored before delivery** — never lost or skipped.
* Both **incoming and outgoing** requests share a single unified process.
* Every transaction is **auditable, replayable, and reliable**.
* The API supports **custom internal relays** or **HTTP dispatches** beyond webhooks.

---

## 🧰 Installation

Install the package via Composer:

```bash
composer require atlasvue/atlas-relay
```

After installation, publish the configuration and migrations:

```bash
php artisan vendor:publish --tag=atlas-relay-config
php artisan vendor:publish --tag=atlas-relay-migrations
php artisan migrate
```

Finally, register the automation scheduler inside your `Console\Kernel`:

```php
use AtlasRelay\Support\RelayScheduler;

protected function schedule(Schedule $schedule): void
{
    RelayScheduler::register($schedule);
}
```

---

## ⚡ Core Concepts

**Relay Flow:**

`Request → Payload Capture → Routing → Outbound Delivery → Complete → Archive`

Each stage of the lifecycle is defined in its own PRD:
- [Payload Capture](./docs/PRD/PRD-Payload-Capture.md): receiving and validating data
- [Routing](./docs/PRD/PRD-Routing.md): determining the correct destination
- [Outbound Delivery](./docs/PRD/PRD-Outbound-Delivery.md): transmitting payloads and handling retries
- [Archiving & Logging](./docs/PRD/PRD-Archiving-and-Logging.md): long-term retention and audit trails

---

## ✨ Feature Highlights

* Unified webhook lifecycle — capture, route, and deliver.
* Receive **and** send webhooks through one consistent API.
* Auto-route inbound webhooks to external destinations.
* Supports synchronous and asynchronous relay modes.
* Retry, delay, and timeout control for delivery reliability.
* Built-in caching, logging, and archiving for performance and scale.

---

## 🧩 Fluent API Examples

Atlas Relay exposes a fluent, chainable API that powers both **inbound and outbound webhook flows**.

### Example A — Capture + Event Execution
```php
Relay::request($request)
    ->payload($payload)
    ->event(fn() => $this->handleEvent($payload));
```
Captures an inbound webhook, stores it, executes a handler, and marks the relay complete.  
(See [Payload Capture](./docs/PRD/PRD-Payload-Capture.md))

### Example B — Capture + Dispatch Event
```php
Relay::request($request)
    ->payload($payload)
    ->dispatchEvent(fn() => $this->handleEvent($payload));
```
Processes an inbound webhook asynchronously. Marks as complete once dispatched successfully.  
(Relates to [Outbound Delivery](./docs/PRD/PRD-Outbound-Delivery.md))

### Example C — Auto-Route Dispatch (Inbound → Outbound)
```php
Relay::request($request)
    ->payload($payload)
    ->dispatchAutoRoute();
```
Receives a webhook and automatically delivers it to the correct outbound destination using your configured routes.  
(Relates to [Routing](./docs/PRD/PRD-Routing.md))

### Example D — Auto-Route Immediate Delivery
```php
Relay::request($request)
    ->payload($payload)
    ->autoRouteImmediately();
```
Performs immediate inbound-to-outbound delivery, returning the response inline.  
(Relates to [Outbound Delivery](./docs/PRD/PRD-Outbound-Delivery.md))

### Example E — Direct Outbound Webhook
```php
Relay::payload($payload)
    ->http()
    ->post('https://api.example.com/webhooks');
```
Sends an outbound webhook directly without route lookup.  
(Relates to [Outbound Delivery](./docs/PRD/PRD-Outbound-Delivery.md))

### Mode Cheat Sheet

| Mode         | Entry Point                             | Notes                                                                               |
|--------------|-----------------------------------------|-------------------------------------------------------------------------------------|
| HTTP         | `Relay::payload()->http()`              | Returns Laravel’s `PendingRequest`; all Http client methods remain available.       |
| Event        | `Relay::request()->event()`             | Executes sync callbacks/listeners and updates lifecycle before bubbling exceptions. |
| Dispatch     | `Relay::payload()->dispatch()`          | Returns native `PendingDispatch`; job middleware records success/failure.           |
| DispatchSync | `Relay::payload()->dispatchSync()`      | Runs immediately in-process with lifecycle tracking.                                |
| Auto-Route   | `Relay::request()->dispatchAutoRoute()` | Resolves routes, copies delivery defaults, and persists before delivery.            |

---

## 🧠 Relay Lifecycle

Every webhook or payload relay is tracked from start to finish in the unified `atlas_relays` table:

| Status         | Description                                 |
|----------------|---------------------------------------------|
| **Queued**     | Payload recorded and awaiting relay action. |
| **Processing** | Relay executing or event dispatched.        |
| **Failed**     | Error occurred; `failure_reason` recorded.  |
| **Completed**  | Relay finished successfully.                |
| **Cancelled**  | Relay manually stopped before completion.   |

Learn more in [PRD — Atlas Relay](./docs/PRD/PRD-Atlas-Relay.md).

---

## 🔁 Retry, Delay & Timeout Handling

Retry logic applies to **AutoRoute** deliveries (typically outbound webhooks).

* **Retry** – Failed deliveries reattempt after `retry_at`.
* **Delay** – Postpones initial delivery.
* **Timeout** – Fails relays exceeding configured duration.

Details: [Outbound Delivery](./docs/PRD/PRD-Outbound-Delivery.md)

---

## 🧭 Routing Behavior

* Matches inbound webhook routes to outbound destinations.
* Supports dynamic paths like `/event/{CUSTOMER_ID}`.
* 20-minute route cache with automatic invalidation on configuration changes.  
  (See [Routing](./docs/PRD/PRD-Routing.md))

---

## 🔍 Observability & Logging

All webhook activity — inbound and outbound — is fully logged:

* Request metadata (source, headers)
* Payload and response details
* Retry attempts and failure causes
* Processing duration and timestamps

Every relay becomes a searchable audit trail of webhook traffic.  
For full schema and retention behavior, see [Archiving & Logging](./docs/PRD/PRD-Archiving-and-Logging.md).

---

## 🗄️ Archiving & Retention

| Variable                   | Default | Description                              |
|----------------------------|---------|------------------------------------------|
| `ATLAS_RELAY_ARCHIVE_DAYS` | 30      | Days before relays move to archive.      |
| `ATLAS_RELAY_PURGE_DAYS`   | 180     | Days before archived relays are deleted. |

---

## 🤝 Contributing

We welcome improvements! Please review the [Contributing Guide](./CONTRIBUTING.md) before opening a pull request. It
covers the mandatory Pint/PHPStan/test workflow, the PRD-driven standards outlined in [AGENTS.md](./AGENTS.md), and the
branching + Conventional Commit rules we enforce across the project. See [AGENTS.md](./AGENTS.md) for the agent workflow
and PRD alignment expectations that apply to every change.

Archiving runs nightly at **10 PM EST**, and purging at **11 PM EST**.  
(Defined in [Archiving & Logging](./docs/PRD/PRD-Archiving-and-Logging.md))

---

## 🧮 Automation Jobs

| Process              | Frequency        | Description                          |
|----------------------|------------------|--------------------------------------|
| Retry overdue        | Every minute     | Retries failed outbound webhooks.    |
| Requeue stuck relays | Every 10 minutes | Restores relays stuck in processing. |
| Timeout enforcement  | Hourly           | Marks expired relays as failed.      |
| Archiving            | Daily (10 PM)    | Moves completed relays to archive.   |
| Purging              | Daily (11 PM)    | Removes expired archive data.        |

### Scheduling

Register the automation cadence inside your `Console\Kernel`:

```php
use AtlasRelay\Support\RelayScheduler;

protected function schedule(Schedule $schedule): void
{
    RelayScheduler::register($schedule);
}
```

Cron expressions and thresholds can be overridden via the `atlas-relay.automation` config options published with the package.

See [Atlas Relay PRD](./docs/PRD/PRD-Atlas-Relay.md) for complete job automation details.

---

## 🔔 Observability Hooks

Atlas Relay dispatches domain events you can listen to without polling the database:

| Event                            | When it fires                                     |
|----------------------------------|---------------------------------------------------|
| `RelayCaptured`                  | Immediately after a relay is persisted.           |
| `RelayAttemptStarted`            | Whenever an outbound attempt begins.              |
| `RelayCompleted` / `RelayFailed` | When an attempt finishes successfully or fails.   |
| `RelayRequeued`                  | When retry/stuck automation re-enqueues a relay.  |
| `RelayRestored`                  | When a relay is restored from the archive.        |
| `AutomationMetrics`              | After automation commands run (counts/durations). |

Use Laravel’s standard event listeners or queued listeners to stream these metrics to your own observability stack.

---

## 🛠 Artisan Helpers

| Command                                   | Description                                      |
|-------------------------------------------|--------------------------------------------------|
| `atlas-relay:routes:seed path.json`       | Seed routes from a JSON file.                    |
| `atlas-relay:relay:inspect {id}`          | Print relay or archived relay state (JSON).      |
| `atlas-relay:relay:restore {id}`          | Move an archived relay back into the live table. |
| `atlas-relay:retry-overdue`               | Requeue relays whose retry window elapsed.       |
| `atlas-relay:requeue-stuck`               | Requeue relays stuck in `processing`.            |
| `atlas-relay:enforce-timeouts`            | Mark long-running relays as timed out.           |
| `atlas-relay:archive` / `:purge-archives` | Manage archiving and purge retention.            |

---

## ⬆️ Upgrading & Publishing

1. **Publish config + migrations**  
   `php artisan vendor:publish --tag=atlas-relay-config`  
   `php artisan vendor:publish --tag=atlas-relay-migrations`
2. **Run migrations**  
   `php artisan migrate`
3. **Register automation scheduler**  
   Call `RelayScheduler::register($schedule)` inside your application’s `Console\Kernel`.
4. **Review new commands/events**  
   Subscribe to the lifecycle events above and enable the automation commands appropriate for your environment.

Each release announces schema changes in `CHANGELOG.md`; run migrations whenever you update the package.

---

## ⚙️ Configuration

| Variable                   | Description                              |
|----------------------------|------------------------------------------|
| `QUEUE_CONNECTION`         | Queue backend for async dispatches.      |
| `ATLAS_RELAY_ARCHIVE_DAYS` | Days before relays are archived.         |
| `ATLAS_RELAY_PURGE_DAYS`   | Days before archived relays are deleted. |

---

## 🚦 Error Mapping

| Condition             | Result                  |
|-----------------------|-------------------------|
| HTTP not 2xx          | `HTTP_ERROR`            |
| Too many redirects    | `TOO_MANY_REDIRECTS`    |
| Redirect host changed | `REDIRECT_HOST_CHANGED` |
| Timeout reached       | `CONNECTION_TIMEOUT`    |
| Payload exceeds 64KB  | `PAYLOAD_TOO_LARGE`     |

Error definitions and enums are in [Outbound Delivery](./docs/PRD/PRD-Outbound-Delivery.md).

---

## 🧪 Example Usage

### Receiving a Webhook and Forwarding Automatically
```php
public function handle(Request $request)
{
    Relay::request($request)
        ->payload($request->all())
        ->dispatchAutoRoute();
}
```

### Sending an Outbound Webhook
```php
Relay::payload(['status' => 'processed'])
    ->http()
    ->post('https://hooks.example.com/receive');
```

### Internal Event Relay
```php
Relay::payload(['id' => 42])
    ->dispatchEvent(fn() => ExampleJob::dispatch());
```

---

## 📘 License

Atlas Relay is open-source software licensed under the [MIT license](./LICENSE).
