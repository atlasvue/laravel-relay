# PRD — Atlas Relay

## Overview

**Atlas Relay** is a Laravel package providing a unified, reliable, and observable system for capturing, processing, and relaying payloads between internal and external destinations. It defines a continuous relay lifecycle where every payload is tracked, processed, and delivered with complete visibility.

Atlas Relay ensures that every payload entering the system is stored, audited, and routed through defined actions, events, or destinations — guaranteeing no payload is lost, and every relay operation is observable end-to-end.

---

## Goals

* Provide a single, fluent API to manage the entire payload lifecycle.
* Ensure all captured payloads are stored and traceable.
* Enable flexible event handling, dispatching, and routing mechanisms.
* Support both synchronous and asynchronous relay modes.
* Maintain auditability and observability across all relay operations.

---

## Relay Flow Summary

**Request → Payload Capture → Event / Dispatch / AutoRoute → Delivery → Complete → Archive**

---

## Core API Patterns

The Relay API provides a consistent, chainable interface for defining how payloads are captured and delivered.

### Example A — Capture + Event Execution

Stores a payload, executes a synchronous event handler, and marks the relay as complete when the event finishes.

```php
Relay::request($request)
    ->payload($payload)
    ->event(fn() => $this->handleEvent($payload));
```

### Example B — Capture + Dispatch Event

Stores payload and dispatches an asynchronous event. The relay is marked complete once the dispatched event succeeds.

```php
Relay::request($request)
    ->payload($payload)
    ->dispatchEvent(fn() => $this->handleEvent($payload));
```

### Example C — Auto-Route Dispatch

Captures a payload and automatically routes it to the correct destination using configured domain and route mapping.

```php
Relay::request($request)
    ->payload($payload)
    ->dispatchAutoRoute();
```

### Example D — Auto-Route Immediate Delivery

Captures payload, performs immediate routing and delivery, and returns the outbound response directly.

```php
Relay::request($request)
    ->payload($payload)
    ->autoRouteImmediately();
```

### Example E — Direct Outbound from Payload

Records payload and performs direct HTTP relay to a destination without route lookup.

```php
Relay::payload($payload)
    ->http()
    ->post('https://api.example.com/webhooks');
```

---

## Functional Summary

* **`Relay::request($request)`** — Captures inbound HTTP request data, normalizes headers, and stores payload.
* **`payload($payload)`** — Defines or overrides stored payload data.
* **`event()` / `dispatchEvent()`** — Executes internal logic before completing relay lifecycle.
* **`dispatchAutoRoute()`** — Uses existing domain/route configurations to determine delivery target.
* **`autoRouteImmediately()`** — Performs synchronous routing and returns response inline.
* **`http()`** — Allows direct delivery to external URLs without configured routes and wraps around laravel's `Http` facade.

---

## Relay Tracking Model

Every relay is tracked from start to finish using a unified **relay record** that represents the entire transaction.

### Table: `atlas_relays`

| Field                  | Type                                 | Description                                                    |
|------------------------|--------------------------------------|----------------------------------------------------------------|
| `id`                   | BIGINT                               | Primary key for each relay event.                              |
| `source`               | VARCHAR(255)                         | Source identifier (IP, origin, or system tag).                 |
| `headers`              | JSON                                 | Headers captured or applied to the relay.                      |
| `payload`              | JSON                                 | Full JSON payload captured.                                    |
| `status`               | TINYINT                              | Current lifecycle state (see below).                           |
| `mode`                 | TINYINT (enum, `Enums\RelayMode`)    | Relay execution mode.                                          |
| `destination_url`      | VARCHAR(255)                         | Target endpoint for outbound or routed delivery.               |
| `response_status`      | INT (nullable)                       | HTTP status code received from destination.                    |
| `response_payload`     | JSON (nullable)                      | Response body returned from destination.                       |
| `failure_reason`       | INT (nullable, `Enums\RelayFailure`) | Enum value describing reason for failure.                      |
| `is_retry`             | BOOLEAN                              | Enables retries for this relay. Initialized from route or API. |
| `retry_seconds`        | INT                                  | Seconds between retry attempts (supports ±25% jitter).         |
| `retry_max_attempts`   | INT                                  | Maximum retry attempts (0 = infinite).                         |
| `is_delay`             | BOOLEAN                              | Enables initial delivery delay for this relay.                 |
| `delay_seconds`        | INT                                  | Number of seconds to delay before first attempt.               |
| `timeout_seconds`      | INT                                  | Max seconds from capture to delivery start.                    |
| `http_timeout_seconds` | INT                                  | Max seconds for outbound HTTP request.                         |
| `retry_at`             | DATETIME (nullable)                  | Next retry timestamp if applicable.                            |
| `created_at`           | DATETIME                             | Record creation timestamp.                                     |
| `updated_at`           | DATETIME                             | Record last update timestamp.                                  |

### Relay Status Enum

| Value | Name           | Description                                                      |
|-------|----------------|------------------------------------------------------------------|
| 0     | **Queued**     | Payload recorded and awaiting relay action.                      |
| 1     | **Processing** | Relay is being executed or event dispatched.                     |
| 2     | **Failed**     | Delivery failed or error occurred. `failure_reason` must be set. |
| 3     | **Completed**  | Delivery or event completed successfully.                        |
| 4     | **Cancelled**  | Relay manually stopped before completion.                        |

---

## Status Handling & Lifecycle Completion

All relay types — regardless of execution mode — are tracked through the same status lifecycle.

* Each relay transitions automatically between **Queued → Processing → (Completed | Failed | Cancelled)** based on execution outcome.
* The relay system ensures consistent updates, even for synchronous event calls or custom event dispatches.
* Internal failures (e.g., exception within an event or failed outbound response) will automatically update status to `Failed` and record the `failure_reason`.

**Automatic completion rules:**

* `event()` and `dispatchEvent()` → Mark `Completed` when the handler or job finishes successfully.
* `dispatchAutoRoute()` and `autoRouteImmediately()` → Mark `Completed` or `Failed` based on the HTTP response or route configuration.
* `http()->post()` → Mark `Completed` or `Failed` based on the outbound response.

---

## Retry, Delay & Timeout Handling

**Configuration Precedence**

1. **Relay-level config (atlas_relays)** — source of truth used at execution time.
2. **Route defaults** — copied into the relay record at creation when AutoRouting matches.
3. **API-specified overrides** — caller may set relay config when creating a relay.

Edits to route config do **not** retroactively change existing relay records.

* Retry, delay, and timeout mechanisms apply **only** to relays executed through **AutoRoute** methods (`dispatchAutoRoute()` and `autoRouteImmediately()`).
* Event-based and direct dispatch relays (`event()`, `dispatchEvent()`, and `http()->post()`) are **not retried** automatically.
* Retry, delay, and timeout configurations are inherited from the routing definitions when enabled.

    * **Retry:** Retries failed deliveries after a defined interval.
    * **Delay:** Defers execution for a given number of seconds before initial delivery.
    * **Timeout:** Enforces total execution or HTTP time limits.

---

## Routing Behavior

* `dispatchAutoRoute()` and `autoRouteImmediately()` both use the existing **domain/route registry** for matching.
* Routing supports both strict and dynamic paths.
* Dynamic segments can contain alphanumeric identifiers (e.g., `/event/{CUSTOMER_ID}`).
* Cached lookups improve performance, with 20-minute cache invalidation on domain/route changes.

---

## Observability & Logging

All relay transactions — including request metadata, payloads, response data, and failure causes — are fully logged.

* Each relay maintains an auditable trail from creation to completion.
* Retry attempts (for AutoRoute mode) are recorded and versioned under a unified history model for full traceability.
* Logging includes execution mode, status transitions, and timing data for performance monitoring.

---

## Archiving & Retention

Archived relays are stored in `atlas_relay_archives`, which mirrors the structure of `atlas_relays`. Automatic archiving and purging follow environment-configurable time windows.

| Variable                   | Default | Description                             |
|----------------------------|---------|-----------------------------------------|
| `ATLAS_RELAY_ARCHIVE_DAYS` | 30      | Days before a relay is archived.        |
| `ATLAS_RELAY_PURGE_DAYS`   | 180     | Days before archived relays are purged. |

Archiving runs nightly at 10 PM EST; purging runs nightly at 11 PM EST.

---

## Automation Jobs

| Process                  | Frequency         | Description                                                          |
|--------------------------|-------------------|----------------------------------------------------------------------|
| **Retry overdue**        | Every minute      | Re-attempts relays past `retry_at` timestamp (AutoRoute only).       |
| **Requeue stuck relays** | Every 10 minutes  | Detects relays stuck in `Processing` beyond threshold and re-queues. |
| **Timeout enforcement**  | Hourly            | Marks relays exceeding configured time limits as `Failed`.           |
| **Archiving**            | Daily (10 PM EST) | Moves old relays to archive table.                                   |
| **Purging**              | Daily (11 PM EST) | Deletes archived records older than retention threshold.             |

---

## Error Mapping

| Condition                     | Result                                   |
|-------------------------------|------------------------------------------|
| HTTP response not 2xx         | `failure_reason = HTTP_ERROR`            |
| Redirect loop or >3 redirects | `failure_reason = TOO_MANY_REDIRECTS`    |
| Redirect host change          | `failure_reason = REDIRECT_HOST_CHANGED` |
| Timeout reached               | `failure_reason = CONNECTION_TIMEOUT`    |
| Payload exceeds 64KB          | `failure_reason = PAYLOAD_TOO_LARGE`     |

---

## Dependencies & References

* **Depends on:** Domains & Routes Registry
* **Feeds into:** Archiving & Retention Module
* **Uses:** Application queue for asynchronous execution

---

## Notes

* Relay-level configuration is persisted on creation (from route defaults or API) and governs execution for the life of the relay.
* Existing relays are immutable with respect to later route config changes.
* All payloads are stored regardless of delivery success.
* The database remains the authoritative record for all relay activity.
* Retry, delay, and timeout mechanisms are exclusive to AutoRoute-based deliveries.
* All other relay types complete or fail based on execution or handler results.
* Relay API provides a fluent, intuitive interface for developers while maintaining complete traceability.
* All operations are idempotent — retries and replays must never duplicate side effects.
