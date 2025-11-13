
# PRD — Payload Capture

## Overview
Payload Capture is the first stage of Atlas Relay. It records every inbound payload—HTTP, internal, or programmatic—storing headers, source IP, and JSON data with full lifecycle visibility. All captures become relay records and move into routing and processing.

---

## Goals
- Persist every inbound request safely.
- Enforce size limits and validation.
- Normalize headers and metadata.
- Support optional AutoRoute lookups with caching.
- Maintain full observability, including failures.

---

## Capture Flow
Inbound Request → Normalize Payload/Headers → Optional Route Lookup → Store Relay Record → Ready for Processing

---

### Relay Record Schema (`atlas_relays`)
| Field                  | Description                                             |
|------------------------|---------------------------------------------------------|
| `id`                   | Relay ID.                                               |
| `source_ip`            | Inbound IPv4 address detected from the request.         |
| `provider`             | Optional integration/provider label (indexed).          |
| `reference_id`         | Optional consumer-provided reference (indexed).         |
| `headers`              | Normalized header JSON.                                 |
| `payload`              | Stored JSON payload.                                    |
| `status`               | Enum: Queued, Processing, Completed, Failed, Cancelled. |
| `mode`                 | event, dispatch, autoroute, direct.                     |
| `failure_reason`       | Enum for capture or downstream failure.                 |
| `response_http_status` | HTTP status of last outbound request.                   |
| `response_payload`     | Truncated last HTTP response body.                      |
| `is_retry`             | AutoRoute retry enabled flag.                           |
| `retry_seconds`        | Delay between retry attempts.                           |
| `retry_max_attempts`   | Max retries allowed.                                    |
| `is_delay`             | Initial delay enabled.                                  |
| `delay_seconds`        | Delay before first execution.                           |
| `timeout_seconds`      | Max relay execution time.                               |
| `http_timeout_seconds` | Max outbound HTTP timeout.                              |
| `next_retry_at`        | Next retry timestamp.                                   |
| `method`               | HTTP verb captured for inbound/outbound delivery.       |
| `url`                  | Normalized route or destination URL applied everywhere. |
| `processing_at`        | When the current attempt began processing.              |
| `completed_at`         | When the relay finished (success, failure, or cancel).  |
| `created_at`           | Capture timestamp.                                      |
| `updated_at`           | Last state change.                                      |

---

## Failure Reason Enum (`Enums\RelayFailure`)
| Code | Label                 | Description              |
|------|-----------------------|--------------------------|
| 100  | UNKNOWN               | Unexpected error.        |
| 101  | PAYLOAD_TOO_LARGE     | Payload exceeds 64KB.    |
| 102  | NO_ROUTE_MATCH        | No route match.          |
| 103  | CANCELLED             | Manually cancelled.      |
| 104  | ROUTE_TIMEOUT         | Routing timeout.         |
| 105  | INVALID_PAYLOAD       | JSON decode failure.     |
| 201  | OUTBOUND_HTTP_ERROR   | Non‑2xx response.        |
| 203  | TOO_MANY_REDIRECTS    | Redirect limit exceeded. |
| 204  | REDIRECT_HOST_CHANGED | Redirect host mismatch.  |
| 205  | CONNECTION_ERROR      | Network/SSL/DNS failure. |
| 206  | CONNECTION_TIMEOUT    | Outbound timeout.        |
| 207  | EXCEPTION             | Uncaught exception.      |

---

## Cache Behavior
- Cache key: domain + path + method.
- Lifetime: 20 minutes.
- Invalidated on domain/route changes.

---

## Observability
All lifecycle details—including retries, responses, durations, and failure reasons—are recorded directly on the `atlas_relays` table.

---

## Edge Cases
- Empty body → stored with empty JSON.
- Malformed body → raw body stored; marked `INVALID_PAYLOAD`.
- Duplicates allowed (dedupe token optional).
- Concurrent requests isolated via atomic inserts.

---
