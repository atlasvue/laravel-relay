# PRD — Payload Capture

## Overview

The **Payload Capture** module defines how Atlas Relay receives, validates, and persists inbound data. It represents the first stage of the relay lifecycle—responsible for transforming external or internal requests into stored, traceable payloads ready for processing, event execution, or routing.

Payload Capture guarantees that all inbound data entering the system is securely recorded with complete metadata (headers, source, payload) and contextual state. It ensures reliability, performance, and observability before a payload transitions into routing or event execution.

---

## Goals

* Capture and persist every inbound request or payload reliably.
* Enforce validation and size constraints for payload integrity.
* Normalize and store headers and metadata for traceability.
* Provide caching mechanisms for efficient route resolution.
* Maintain full visibility of payloads, including failures and status transitions.
* Ensure no inbound data is lost, even in error conditions.

---

## Capture Flow Summary

**Inbound Request** → Payload Normalization → Header Capture → Route Resolution (optional) → Stored Record → Ready for Relay Processing

---

## Functional Description

### 1. Inbound Entry Point

Payloads may originate from external HTTP requests, internal dispatches, or programmatic calls via the `Relay::request()` API.

* All inbound data must be recorded before any routing, dispatching, or event execution.
* Each record is assigned a unique ID immediately upon creation.
* Payload capture may occur synchronously (direct execution) or asynchronously (queued relay processing).

### 2. Header Normalization

* All request headers are captured and stored in lowercase as a JSON object.
* Duplicate headers are merged by key, preferring the last seen value.
* Sensitive headers (e.g., `authorization`) are masked before storage, unless explicitly whitelisted.

### 3. Payload Handling

* Payloads are stored as JSON objects.
* Maximum payload size: **64KB**.
* If size exceeds this limit, the payload is rejected with `PAYLOAD_TOO_LARGE` and the record is marked `Failed`.
* Empty payloads are permitted but still recorded with their metadata.
* When JSON decoding fails, the raw request body is stored without normalization; status is set to `Failed` with `failure_reason = INVALID_PAYLOAD`.

### 4. Route Resolution

If AutoRoute or route mapping is enabled:

* The system attempts to resolve a route (method + path) from the Routing module.
* If no match is found, the record is stored as `Failed` with `failure_reason = NO_ROUTE_MATCH`.
* Route matches are cached for **20 minutes** to minimize lookup latency.
* Cache invalidates automatically when domain or route configurations are modified.

### 5. Record Creation

All payloads are persisted to the `atlas_relays` table (shared lifecycle model) with the following core metadata:

| Field            | Description                                                         |
|------------------|---------------------------------------------------------------------|
| `id`             | Unique relay ID.                                                    |
| `request_source` | Origin source (IP, system name, or identifier).                     |
| `headers`        | Normalized header JSON.                                             |
| `payload`        | Captured JSON payload.                                              |
| `status`         | Lifecycle state (Queued, Processing, Failed, Completed, Cancelled). |
| `mode`           | Relay mode (event, dispatch, autoroute, direct).                    |
| `failure_reason` | Enum describing reason for capture failure, if applicable.          |
| `created_at`     | Timestamp of capture.                                               |
| `updated_at`     | Timestamp of last state change.                                     |

### 6. Status Handling

* All new captures begin with `status = Queued`.
* Status changes occur automatically through relay lifecycle transitions.
* If capture validation fails (e.g., payload too large, blocked source), status is immediately set to `Failed`.

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
| 103  | CANCELLED             | Relay manually cancelled (applies when status = 4).                 |
| 104  | ROUTE_TIMEOUT         | Time exceeded between inbound receipt and configured route timeout. |
| 105  | INVALID_PAYLOAD       | Payload body failed JSON decoding; raw request stored and marked failed. |
| 201  | OUTBOUND_HTTP_ERROR   | Outbound response returned a non-2xx HTTP status code.              |
| 203  | TOO_MANY_REDIRECTS    | Redirect limit (3) exceeded during outbound request.                |
| 204  | REDIRECT_HOST_CHANGED | Redirect attempted to a different host (security risk).             |
| 205  | CONNECTION_ERROR      | Outbound delivery failed due to network, SSL, or DNS issues.        |
| 206  | CONNECTION_TIMEOUT    | Outbound delivery timed out before receiving a response.            |
| 207  | EXCEPTION             | Uncaught exception during event/dispatch execution.                 |
## Cache Behavior

* Route lookups cached by (domain, path, method) key for **20 minutes**.
* Cache invalidated when domain or route is created, updated, or deleted.
* Cache layer operates independently of database consistency—the database remains authoritative.

---

## Observability & Logging

* Every capture logs request metadata, payload size, and processing duration.
* Logs include:

    * Capture start and completion times
    * Route match status
    * Failure reason (if applicable)
* Captures are fully queryable by ID, status, and route association.

---

## Edge Cases & Behavior Rules

* **Empty Body Requests:** Still stored with status `Queued` and `payload = {}`.
* **Malformed JSON:** Raw request body stored without normalization; record captured but marked `Failed` with `INVALID_PAYLOAD`.
* **Duplicate Requests:** Duplicates allowed but logged with deduplication token if provided.
* **Concurrent Requests:** Atomic insertion ensures each capture event is isolated.

---

## Lifecycle Flow Summary

**Captured → Queued → Processing → (Completed | Failed | Cancelled) → Archived**

Each relay passes through a defined sequence of states that represent its progression from intake to resolution.

---

---

## Dependencies & Integration

* **Depends on:** [PRD — Payload Capture](./PRD-Payload-Capture.md), [PRD — Routing](./PRD-Routing.md)

---

## Notes

* Payload capture forms the foundation of all relay functionality—no downstream processing occurs without a valid capture.
* Failed payloads remain stored for observability and can be manually retried or replayed.
* Schema follows Atlas Relay conventions (no foreign keys, UTC timestamps, JSON data fields).
* All operations must be idempotent—no duplicate inserts or side effects across retries.

---

## Outstanding Questions / Clarifications

* Should cached route lookups include wildcard domain support?
* Should payload capture support content-types other than JSON (e.g., XML or form-encoded)?

---

### See Also
* [PRD — Atlas Relay](./PRD-Atlas-Relay.md)
* [PRD — Payload Capture](./PRD-Payload-Capture.md)
* [PRD — Routing](./PRD-Routing.md)
* [PRD — Outbound Delivery](./PRD-Outbound-Delivery.md)
* [PRD — Archiving & Logging](./PRD-Archiving-and-Logging.md)
* [PRD — Example Usage](./PRD-Example-Usage.md)
