
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
Relay::request($req)->payload($payload)->event(fn() => ...);
```

### Capture + Dispatch Event
```php
Relay::request($req)->payload($payload)->dispatchEvent(fn() => ...);
```

### Auto‑Route (Dispatch)
```php
Relay::request($req)->payload($p)->dispatchAutoRoute();
```

### Auto‑Route (Immediate)
```php
Relay::request($req)->payload($p)->autoRouteImmediately();
```

### Direct HTTP
```php
Relay::payload($p)->http()->post('https://example.com');
```

---

## Functional Summary
- **Relay::request()** captures inbound HTTP, normalizes headers, stores payload.
- **payload()** sets stored payload.
- **event() / dispatchEvent()** run internal logic (sync or queued).
- **dispatchAutoRoute()** uses domain/route mapping.
- **autoRouteImmediately()** delivers synchronously and returns response.
- **http()** sends direct outbound HTTP (Laravel `Http` wrapper).
- **dispatch()** uses Laravel’s native dispatch with lifecycle tracking.

---

## Relay Tracking Model
Every relay is represented by a unified record containing the entire transaction.  
Full schema lives in **Payload Capture PRD** (`atlas_relays` includes all request, response, retry, timeout, and lifecycle fields).

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
- `event()` / `dispatchEvent()` → complete when handler/job succeeds.
- AutoRoute variants complete/fail based on HTTP outcome.
- Direct HTTP completes/fails based on response.

---

## Retry, Delay & Timeout Logic
Applies **only to AutoRoute** deliveries.

Source of configuration (priority):
1. Relay record fields.
2. Route defaults (copied at creation).
3. API overrides.

Rules:
- Retries: governed by `is_retry`, `retry_seconds`, `retry_max_attempts`.
- Delays: `is_delay`, `delay_seconds`.
- Timeouts: `timeout_seconds`, `http_timeout_seconds`.
- Edits to route config do not affect existing relays.

---

## Routing Behavior
- AutoRoute uses domain + route registry.
- Supports strict and dynamic paths (e.g., `/event/{ID}`).
- Route lookups cached for 20 minutes; cache invalidated when config changes.

---

## Observability
All lifecycle data is stored inline on `atlas_relays`:  
status, failure_reason, retries, durations, `response_http_status`, `response_payload`, timing, and configuration fields.

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
- Relay-level config is immutable after creation.
- All payloads stored regardless of delivery result.
- Malformed JSON stored as‑is with `INVALID_PAYLOAD`.
- All operations must be idempotent.
