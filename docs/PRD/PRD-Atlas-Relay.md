
# PRD — Atlas Relay

## Overview
Atlas Relay provides a unified, reliable system to capture, process, route, and deliver payloads. Every relay is fully tracked end‑to‑end with complete visibility and auditability.

---

## Goals
- Single fluent API for full relay lifecycle.
- Guaranteed storage and traceability of all payloads.
- Support event, dispatch, AutoRoute, and direct HTTP.
- Unified synchronous/asynchronous behavior.
- Complete lifecycle observability.

---

## Relay Flow
Request → Capture → Event/Dispatch/AutoRoute → Delivery → Complete → Archive

---

## Core API Patterns

### Capture + Event
```php
Relay::request($req)->event(fn($payload) => ...);
```

### Capture + Dispatch Job
```php
Relay::request($req)->dispatch(new ExampleJob($payload));
```

### Auto‑Route (Dispatch)
```php
Relay::request($req)->dispatchAutoRoute();
```

### Auto‑Route (Immediate)
```php
Relay::request($req)->autoRouteImmediately();
```

### Direct HTTP
```php
Relay::http()->post('https://example.com', ['payload' => true]);
```

---

## Functional Summary
- **Relay::request()** captures inbound HTTP, normalizes headers, stores payload, and exposes that payload directly on the builder for immediate routing/delivery usage.
- **payload()** sets stored payload (optional when using `Relay::http()` because payload is captured from the request data).
- **event()** runs internal logic synchronously.
- **dispatchAutoRoute()** uses domain/route mapping.
- **autoRouteImmediately()** delivers synchronously and returns response.
- **http()** sends direct outbound HTTP (Laravel `Http` wrapper).
- **dispatch()** uses Laravel’s native dispatch with lifecycle tracking.

---

## Relay Tracking Model
Every relay is represented by a unified record containing the entire transaction.  
Full schema lives in **Payload Capture PRD** (`atlas_relays` includes all request, response, and lifecycle fields). Retry/delay/timeout configuration is defined on `atlas_relay_routes` and referenced via `route_id`.

---

## Database Requirements
- Configurable database connection: `atlas-relay.database.connection` (`ATLAS_RELAY_DATABASE_CONNECTION`).
- Defaults to app’s primary connection.
- All models/migrations must respect this setting.

---

## Status Lifecycle
**Queued → Processing → Completed | Failed | Cancelled**

Rules:
- All relay types use the same lifecycle.
- Exceptions or failed outbound responses set status to `Failed` and populate `failure_reason`.
- `event()` → completes when handler succeeds.
- AutoRoute variants complete/fail based on HTTP outcome.
- Direct HTTP completes/fails based on response.

---

## Retry, Delay & Timeout Logic
Applies **only to AutoRoute** deliveries.

Source of configuration:
- `atlas_relay_routes` stores retry/delay/timeout knobs.
- Relay records point to the route via `route_id`; automation reads the latest route definition whenever it needs thresholds.
- Manual relays (no `route_id`) do not participate in these automation features.

Rules:
- Retries: governed by the route’s `is_retry`, `retry_seconds`, `retry_max_attempts`.
- Delays: `is_delay`, `delay_seconds`.
- Timeouts: `timeout_seconds`, `http_timeout_seconds`.
- Updating a route immediately affects future enforcement runs because configuration is no longer copied onto the relay.

---

## Routing Behavior
- AutoRoute uses domain + route registry.
- Supports strict and dynamic paths (e.g., `/event/{ID}`).
- Route lookups cached for 20 minutes; cache invalidated when config changes.

---

## Observability
All lifecycle data is stored inline on `atlas_relays`:  
status, failure_reason, attempts, durations, `response_http_status`, `response_payload`, and scheduling timestamps. Retry/delay/timeout configuration is resolved dynamically from the associated route.

---

## Archiving & Retention
Historical records migrate to `atlas_relay_archives` (schema mirrors `atlas_relays`).

| Var                        | Default | Meaning            |
|----------------------------|---------|--------------------|
| `ATLAS_RELAY_ARCHIVE_DAYS` | 30      | Age before archive |
| `ATLAS_RELAY_PURGE_DAYS`   | 180     | Age before purge   |

Archiving: 10 PM EST  
Purging: 11 PM EST

---

## Automation Jobs
| Job                  | Frequency    | Purpose                      |
|----------------------|--------------|------------------------------|
| Retry overdue        | Every min    | Retry relays past `next_retry_at` |
| Requeue stuck relays | Every 10 min | Requeue long‑running relays  |
| Timeout enforcement  | Hourly       | Mark timed‑out relays failed |
| Archiving            | Daily        | Move old relays              |
| Purging              | Daily        | Delete old archives          |

---

## Notes
- HTTP & Dispatch use Laravel‑native APIs; Atlas Relay only intercepts for lifecycle recording.
- Route-level config is the single source of truth for retries/delays/timeouts.
- All payloads stored regardless of delivery result.
- Malformed JSON stored as‑is with `INVALID_PAYLOAD`.
- All operations must be idempotent.
