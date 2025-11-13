
# PRD — Outbound Delivery

## Overview
Outbound Delivery handles how Atlas Relay sends payloads to external HTTP endpoints or executes internal events and queued jobs. It completes the relay lifecycle with consistent delivery rules, retries, timeouts, and full observability recorded on the `atlas_relays` table.

---

## Goals
- Provide consistent outbound execution (HTTP, Event, Dispatch).
- Ensure reliability with retries, delays, and timeout enforcement.
- Capture all responses, failures, and state transitions.
- Maintain lifecycle traceability back to each relay.

---

## Outbound Flow
Captured → Mode Selected → Execute (HTTP/Event/Dispatch) → Record Outcome → Retry/Timeout → Complete/Fail → Archive

---

## Outbound Modes

| Mode         | Description                                   |
|--------------|-----------------------------------------------|
| **HTTP**     | Sends payload to an external URL.             |
| **Event**    | Executes a synchronous Laravel event/closure. |
| **Dispatch** | Dispatches an asynchronous queued job.        |

Every outbound execution belongs to a single relay; fan-out is supported when configuration defines multiple deliveries.

---

## Native Laravel Wrapper Design
Outbound Delivery wraps Laravel’s built‑in `Http` and job dispatching:

- **HTTP:** Uses Laravel’s `Http` facade. Relay intercepts responses/exceptions to update lifecycle state before returning control.
- **Dispatch:** Uses native `dispatch()` mechanisms. Relay attaches minimal middleware to capture success/failure without altering job design.
- **Goal:** Keep Laravel-native ergonomics while guaranteeing lifecycle tracking.

---

## Execution Rules

### HTTP
- Uses configured method; payload from `atlas_relays.payload`.
- Merges relay/route/domain headers.
- HTTPS required; redirects limited to 3; host changes blocked.
- Relay records:
    - `response_http_status`
    - `response_payload` (truncated)
- Exceptions map to `RelayFailure` and set status `Failed`.

### Event
- Executes synchronously.
- Exceptions mark relay as `Failed` with `EXCEPTION`.

### Dispatch
- Dispatches queued jobs.
- Relay stays `Processing` until job completes.
- Success → `Completed`
- Failure or max attempts exceeded → `Failed` with mapped failure reason.

---

## Retry, Delay & Timeout

Execution is controlled by relay-level fields:

- Retries: `is_retry`, `retry_seconds`, `retry_max_attempts`
- Delays: `is_delay`, `delay_seconds`
- Timeouts: `timeout_seconds`, `http_timeout_seconds`

Rules:
- Delays apply only to the first attempt.
- Retries scheduled with `next_retry_at`.
- Timeouts apply to total execution time.
- Previous failure details cleared on retry.

---

## Lifecycle Transitions

| State                  | Trigger                        |
|------------------------|--------------------------------|
| Queued → Processing    | Execution begins               |
| Processing → Completed | Successful HTTP/Event/Job      |
| Processing → Failed    | HTTP error, exception, timeout |
| Processing → Cancelled | Manual cancel                  |

---

## Failure Reason Enum (`Enums\RelayFailure`)

| Code | Label                 | Description              |
|------|-----------------------|--------------------------|
| 100  | UNKNOWN               | Unexpected error         |
| 101  | PAYLOAD_TOO_LARGE     | Payload >64KB            |
| 102  | NO_ROUTE_MATCH        | No route match           |
| 103  | CANCELLED             | Manually cancelled       |
| 104  | ROUTE_TIMEOUT         | Route resolution timeout |
| 201  | OUTBOUND_HTTP_ERROR   | Non‑2xx                  |
| 203  | TOO_MANY_REDIRECTS    | >3 redirects             |
| 204  | REDIRECT_HOST_CHANGED | Redirect host mismatch   |
| 205  | CONNECTION_ERROR      | Network/SSL/DNS          |
| 206  | CONNECTION_TIMEOUT    | Outbound timeout         |
| 207  | EXCEPTION             | Uncaught exception       |

---

## Observability
All outbound attempts, responses, retries, timings, and failures are recorded directly in the `atlas_relays` table.

---

## Automation Jobs

| Job                      | Frequency        | Description                                |
|--------------------------|------------------|--------------------------------------------|
| Retry Overdue Deliveries | Every minute     | Attempts relays past `next_retry_at`.      |
| Requeue Stuck Jobs       | Every 10 minutes | Requeues relays in `Processing` too long.  |
| Timeout Enforcement      | Hourly           | Marks long-running deliveries as `Failed`. |

---

## Archiving

| Variable                 | Default | Description          |
|--------------------------|---------|----------------------|
| ATLAS_RELAY_ARCHIVE_DAYS | 30      | Days before archival |
| ATLAS_RELAY_PURGE_DAYS   | 180     | Days before purge    |

---

## Notes
- All outbound executions are idempotent.
- HTTPS is mandatory.
- Event and Dispatch modes share the same tracking pipeline.
- Wrappers remain non-intrusive; user code remains unchanged.

---

## Outstanding Questions
- Should Event/Dispatch retries mirror HTTP retry rules?
- Should fan-out be a core feature or extension?
- Should multi-target results collapse into a single relay status?
