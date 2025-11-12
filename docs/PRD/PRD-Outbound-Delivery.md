# PRD — Outbound Delivery

## Overview

The **Outbound Delivery** module defines how Atlas Relay transmits payloads to destinations or executes event-driven handlers after a successful payload capture. It manages both **external HTTP deliveries** and **internal event dispatches**, unifying the mechanisms that complete the relay lifecycle.

Outbound Delivery ensures reliability, traceability, and control over all outbound executions—whether they are routed HTTP calls, internal event triggers, or asynchronous job dispatches. It provides structured retry logic, timeout enforcement, and detailed observability for every outbound operation.

---

## Goals

* Deliver all relay payloads through consistent, observable mechanisms.
* Support multiple outbound modes: **HTTP**, **Event**, and **Dispatch**.
* Guarantee reliability via retries, delays, and timeout enforcement.
* Log responses and outcomes for all outbound executions.
* Maintain full lifecycle traceability back to the originating relay record.

---

## Outbound Flow Summary

**Relay Captured** → Delivery Mode Selected → Outbound Record Created → Execution (HTTP / Event / Dispatch) → Response Logged → Retry / Timeout → Complete → Archive

---

## Functional Description

### 1. Outbound Modes

Outbound delivery supports three execution modes:

| Mode         | Description                                                      |
|--------------|------------------------------------------------------------------|
| **HTTP**     | Sends payload to an external URL via HTTP POST/PUT/DELETE/etc.   |
| **Event**    | Executes a synchronous Laravel event handler or closure.         |
| **Dispatch** | Dispatches a queued job asynchronously for background execution. |

Each outbound operation is linked to a single `atlas_relays` record. A relay may produce multiple outbound executions when configured to fan out.

---

### 2. Laravel‑Native Wrappers (Design Intent)

Atlas Relay’s **HTTP** and **Dispatch** features are **thin wrappers** over Laravel’s native capabilities to avoid new or unfamiliar APIs while preserving **full user control**:

* **HTTP wrapper:** delegates to Laravel’s `Http` facade under the hood. All standard options (headers, query, body, middleware, retries/backoff, etc.) remain available to callers. Atlas Relay **intercepts results and exceptions** to record lifecycle state before the response is returned to the caller.
* **Dispatch wrapper:** delegates to Laravel job dispatch (`dispatch()`, `dispatchSync()`, `Bus::dispatch()`). Jobs remain first‑class user code. Atlas Relay **attaches job middleware/wrappers** to capture **success/failure** and update the relay lifecycle automatically.

> Goal: **Native ergonomics, zero new concepts**, while ensuring **automatic lifecycle tracking**.

---

### 3. Outbound Execution Rules

#### HTTP Mode

* Executes using configured method (default: POST).
* Payload body is taken from the `atlas_relays.payload` field.
* Merges headers from relay, route, and domain configurations.
* HTTPS is mandatory; non-secure targets are rejected.
* Redirects limited to 3; host changes are disallowed.

**HTTP Interception & Lifecycle Tracking**

* The HTTP wrapper **executes the request via `Http`** and **captures outcome first**, then returns the response to the caller.
* On completion, it records:
    * `response_status` (HTTP status code)
    * `response_payload` (response body, truncated per storage rules if applicable)
    * timing/attempt metadata and any redirects encountered
* On exception (network/SSL/DNS/timeout), it maps the error to `Enums\RelayFailure` and updates the relay to `Failed` **before** propagating or returning standardized error info.
* When called in **synchronous** flows (e.g., `autoRouteImmediately()`), interception ensures the relay status is finalized prior to returning control to the consumer.

#### Event Mode

* Executes a synchronous function or Laravel event listener defined in relay configuration.
* The relay completes when the event handler returns successfully.
* Any thrown exception sets relay status to `Failed` and logs `failure_reason = EXCEPTION`.

#### Dispatch Mode

* Dispatches an asynchronous job (implements `ShouldQueue`).
* The relay remains in `Processing` until the dispatched job reports success or failure.
* Supports Laravel’s queue retry and backoff configurations.

**Dispatch Wrapper & Lifecycle Tracking**

Atlas Relay attaches **job middleware / a small wrapper** to preserve native job ergonomics while guaranteeing lifecycle tracking:

* On **successful job completion**, the wrapper marks the originating relay **`Completed`** and records duration/attempts.
* On **job failure** (exception or exceeded attempts), the wrapper sets status **`Failed`** with mapped `failure_reason` and prepares retry if configured.
* For **manual failure signaling**, jobs may call a small helper to set a specific `failure_reason` while still allowing native exception handling.
* Wrapper MUST be non‑intrusive: it **does not change** user job signatures and **does not require** custom base classes.

---

### 4. Retry, Delay & Timeout Handling

Outbound Delivery provides fault-tolerance mechanisms that apply to HTTP and Dispatch modes.

**Configuration Precedence**

1. **Relay-level fields (`atlas_relays`):** `is_retry`, `retry_seconds`, `retry_max_attempts`, `is_delay`, `delay_seconds`, `timeout_seconds`, `http_timeout_seconds`.
2. **Route defaults:** used only to initialize the relay at creation (AutoRouting) when relay fields are not explicitly set by the API.

Schedulers and workers must read values from the **relay record** at execution time.

| Behavior    | Description                                                               |
|-------------|---------------------------------------------------------------------------|
| **Retry**   | Retries failed deliveries up to a defined maximum (`retry_max_attempts`). |
| **Delay**   | Defers initial execution by `delay_seconds` before first attempt.         |
| **Timeout** | Aborts operations exceeding the configured duration (`timeout_seconds`).  |

#### Rules

* Retries are scheduled based on `retry_at` timestamps.
* Delays apply only to the first attempt.
* Timeouts apply to total execution duration, not individual network requests.
* When a retry is triggered, prior failure details are cleared.

---

### 5. Lifecycle Transitions

All outbound deliveries follow the same lifecycle sequence:

**Queued → Processing → (Completed | Failed | Cancelled)**

| Transition               | Trigger                                         |
|--------------------------|-------------------------------------------------|
| `Queued → Processing`    | Delivery begins execution.                      |
| `Processing → Completed` | Response received or job finished successfully. |
| `Processing → Failed`    | Non-2xx HTTP code, exception, or timeout.       |
| `Processing → Cancelled` | Manually cancelled by system or operator.       |

---

## Failure Reason Enum

Failure reasons are defined and managed centrally via the PHP enum:

```php
Enums\RelayFailure
```

* **Stored in database as nullable INT.**
* **`NULL`** represents a relay that has not failed.
* Enum provides consistent codes, labels, and descriptions across all modules.

| Code | Label                 | Description                                                         |
|------|-----------------------|---------------------------------------------------------------------|
| 100  | UNKNOWN               | Unexpected or uncategorized error.                                  |
| 101  | PAYLOAD_TOO_LARGE     | Payload exceeds size limit (64KB). Not retried.                     |
| 102  | NO_ROUTE_MATCH        | No matching route found for inbound path/method.                    |
| 103  | CANCELLED             | Relay manually cancelled (applies when status = `RelayStatus::CANCELLED`, value `4`). |
| 104  | ROUTE_TIMEOUT         | Time exceeded between inbound receipt and configured route timeout. |
| 201  | OUTBOUND_HTTP_ERROR   | Outbound response returned a non-2xx HTTP status code.              |
| 203  | TOO_MANY_REDIRECTS    | Redirect limit (3) exceeded during outbound request.                |
| 204  | REDIRECT_HOST_CHANGED | Redirect attempted to a different host (security risk).             |
| 205  | CONNECTION_ERROR      | Outbound delivery failed due to network, SSL, or DNS issues.        |
| 206  | CONNECTION_TIMEOUT    | Outbound delivery timed out before receiving a response.            |
| 207  | EXCEPTION             | Uncaught exception during event/dispatch execution.                 |

## Observability

All lifecycle and activity details are recorded directly in the `atlas_relays` table. Each relay entry captures inbound and outbound events, status transitions, retry counts, durations, responses, and failure reasons inline.
## Automation Jobs

| Job                          | Frequency        | Description                                                        |
|------------------------------|------------------|--------------------------------------------------------------------|
| **Retry Overdue Deliveries** | Every minute     | Re-attempts deliveries with expired `retry_at` timestamps.         |
| **Requeue Stuck Jobs**       | Every 10 minutes | Detects jobs in `Processing` state beyond threshold and re-queues. |
| **Timeout Enforcement**      | Hourly           | Marks deliveries exceeding their timeout as `Failed`.              |

---

## Data Retention & Archiving

Outbound records older than configured retention period are migrated to archive tables for long-term storage.

| Variable                   | Default | Description                         |
|----------------------------|---------|-------------------------------------|
| `ATLAS_RELAY_ARCHIVE_DAYS` | 30      | Days before delivery archived.      |
| `ATLAS_RELAY_PURGE_DAYS`   | 180     | Days before archived record purged. |

Archiving runs nightly at 10 PM EST; purging at 11 PM EST.

---

## Dependencies & Integration

* **Depends on:** [PRD — Payload Capture](./PRD-Payload-Capture.md), [PRD — Routing](./PRD-Routing.md)
* **Integrates with:** [PRD — Atlas Relay](./PRD-Atlas-Relay.md) lifecycle and job automation

---

## Notes

* All outbound executions are idempotent—no duplicate side effects across retries.
* Outbound results must be auditable and queryable per relay.
* HTTP targets must use HTTPS; redirects and cross-domain hops are blocked.
* Dispatch and Event modes share the same observability pipeline for unified tracking.
* **Wrappers are non‑intrusive:** callers retain full control over Laravel’s `Http` and job features; Atlas Relay only intercepts to **guarantee lifecycle updates**.

---

## Outstanding Questions / Clarifications

* Should event and dispatch failures follow the same retry policy as HTTP requests?
* Should outbound fan-out (multiple destinations per relay) be supported in core, or as an extension?
* Should outbound results be summarized into a single relay status or multiple partial states?
