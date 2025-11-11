# Atlas Relay

> A unified Laravel relay system for **sending and receiving webhooks** — built for **reliability**, **observability**, and **control**. Capture, process, and relay any payload with full lifecycle visibility.

---

## 🌍 Overview

**Atlas Relay** is a Laravel package that provides a **complete relay system** for managing both **inbound and outbound webhooks**. It unifies webhook reception, processing, and delivery into one lifecycle — ensuring every payload is captured, tracked, and delivered with full transparency.

While designed for webhook orchestration, Atlas Relay’s fluent API can handle **any type of payload relay**, from internal events to external HTTP requests.

### Why Atlas Relay?

Webhook handling is notoriously fragile — missing retries, inconsistent logging, and scattered error handling. Atlas Relay eliminates these pain points with a **durable, observable pipeline** that guarantees delivery and traceability.

Atlas Relay ensures:

* Every webhook is **stored before delivery** — never lost or skipped.
* Both **incoming and outgoing** requests share a single unified process.
* Every transaction is **auditable, replayable, and reliable**.
* The API can support **custom internal relays** or **HTTP dispatches** beyond webhooks.

---

## ⚡ Core Concepts

**Relay Flow:**

`Request → Payload Capture → Event / Dispatch / AutoRoute → Delivery → Complete → Archive`

### Key Principles

* **Reliability:** Every webhook or payload is persisted before processing.
* **Visibility:** All relay states, payloads, and results are logged end-to-end.
* **Flexibility:** Use it for inbound webhooks, outbound API calls, or background jobs.
* **Auditability:** Every relay has a full record — nothing disappears silently.

---

## ✨ Feature Highlights

* Unified webhook lifecycle — capture, route, and deliver.
* Receive and send webhooks through one consistent API.
* Auto-route inbound webhooks to external destinations.
* Supports synchronous and asynchronous modes.
* Retry, delay, and timeout control for delivery reliability.
* Built-in caching, logging, and archiving for scale.

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

### Example B — Capture + Dispatch Event

```php
Relay::request($request)
    ->payload($payload)
    ->dispatchEvent(fn() => $this->handleEvent($payload));
```

Processes an inbound webhook asynchronously. Marks as complete once dispatched successfully.

### Example C — Auto-Route Dispatch (Inbound → Outbound)

```php
Relay::request($request)
    ->payload($payload)
    ->dispatchAutoRoute();
```

Receives a webhook and automatically delivers it to the correct outbound destination using your configured routes.

### Example D — Auto-Route Immediate Delivery

```php
Relay::request($request)
    ->payload($payload)
    ->autoRouteImmediately();
```

Performs immediate inbound-to-outbound delivery, returning the response inline.

### Example E — Direct Outbound Webhook

```php
Relay::payload($payload)
    ->http()
    ->post('https://api.example.com/webhooks');
```

Sends an outbound webhook directly without route lookup.

---

## 🧠 Relay Lifecycle

Every webhook or payload relay is tracked from start to finish in the `atlas_relays` table:

| Status         | Description                                 |
|----------------|---------------------------------------------|
| **Queued**     | Payload recorded and awaiting relay action. |
| **Processing** | Relay executing or event dispatched.        |
| **Failed**     | Error occurred, `failure_reason` recorded.  |
| **Completed**  | Relay finished successfully.                |
| **Cancelled**  | Relay manually stopped before completion.   |

---

## 🔁 Retry, Delay & Timeout Handling

Retry logic applies to **AutoRoute** deliveries — especially useful for outbound webhooks.

* **Retry**: Failed deliveries reattempt after `retry_at`.
* **Delay**: Postpones initial delivery by seconds.
* **Timeout**: Fails relays exceeding duration limits.

Event-based and direct deliveries (`event()`, `dispatchEvent()`, `http()->post()`) complete immediately and are not retried.

---

## 🧭 Routing Behavior

* Maps inbound webhook routes to outbound destinations.
* Supports dynamic paths like `/event/{CUSTOMER_ID}`.
* 20-minute cache with auto-invalidation after route changes.

---

## 🔍 Observability & Logging

All webhook activity — inbound and outbound — is fully logged:

* Request metadata (source, headers)
* Payload and response details
* Retry attempts and failure causes
* Processing duration and timestamps

Every relay is a complete, searchable audit trail of webhook traffic.

---

## 🗄️ Archiving & Retention

| Variable                   | Default | Description                              |
|----------------------------|---------|------------------------------------------|
| `ATLAS_RELAY_ARCHIVE_DAYS` | 30      | Days before relays move to archive.      |
| `ATLAS_RELAY_PURGE_DAYS`   | 180     | Days before archived relays are deleted. |

Archiving runs nightly at **10 PM EST**; purging at **11 PM EST**.

---

## 🧮 Automation Jobs

| Process              | Frequency        | Description                          |
|----------------------|------------------|--------------------------------------|
| Retry overdue        | Every minute     | Retries failed outbound webhooks.    |
| Requeue stuck relays | Every 10 minutes | Restores relays stuck in processing. |
| Timeout enforcement  | Hourly           | Marks expired relays as failed.      |
| Archiving            | Daily (10 PM)    | Moves completed relays to archive.   |
| Purging              | Daily (11 PM)    | Removes expired archive data.        |

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

## 🤝 Contributing

Atlas Relay is designed for extensibility across webhook and payload delivery systems. Contributions to routing, visibility, or lifecycle handling are encouraged.

### Local Setup

```bash
composer install
php artisan migrate
```

Run tests:

```bash
php artisan test
```

---

## 👥 Contributors

We welcome collaboration from contributors and agents helping improve Atlas Relay’s ecosystem.  
See the [AGENTS.md](./AGENTS.md) file to learn more about how to participate.

---

## 📘 License

Atlas Relay is open-source software licensed under the [MIT license](./LICENSE).
