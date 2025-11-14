# PRD — Atlas Relay

## Purpose
Atlas Relay is the **authoritative system specification** for how all relays (inbound, outbound, or system‑created) are captured, processed, tracked, and archived.  
It defines the **full data model**, **full lifecycle**, and **failure semantics** used across every PRD.

Specific flows:
- Inbound/receive behavior → **[Receive Webhook Relay](./Receive-Webhook-Relay.md)**
- Outbound/send behavior → **[Send Webhook Relay](./Send-Webhook-Relay.md)**
- Archival retention → **[Archiving & Logging](./Archiving-and-Logging.md)**
- Usage & examples → **[Example Usage](./Example-Usage.md)**

This document defines the unified requirements all other PRDs rely on.

---

# 1. Relay Lifecycle (Core System Behavior)

**Request or Payload → Capture → Execute (Event / Dispatch / HTTP) → Complete/Fail/Cancel → Archive**

Lifecycle applies to **all** relay types.

---

# 2. Relay Data Model (Full Field Specification)

The following fields **must exist** on both `atlas_relays` and `atlas_relay_archives`.  
Archive table must mirror this schema exactly (plus `archived_at`).

| Field                  | Description                                                                   |
|------------------------|-------------------------------------------------------------------------------|
| `id`                   | Primary key                                                                   |
| `type`                 | RelayType enum (`INBOUND`, `OUTBOUND`, `RELAY`)                               |
| `status`               | RelayStatus enum (`QUEUED`, `PROCESSING`, `COMPLETED`, `FAILED`, `CANCELLED`) |
| `provider`             | Optional integration label                                                    |
| `reference_id`         | Optional external reference ID                                                |
| `source_ip`            | Inbound only — captured from request                                          |
| `headers`              | Normalized JSON headers (sensitive masked)                                    |
| `payload`              | JSON payload or raw body                                                      |
| `method`               | HTTP verb                                                                     |
| `url`                  | Full request or outbound destination URL                                      |
| `failure_reason`       | RelayFailure enum                                                             |
| `meta`                 | Consumer-defined JSON metadata                                                |
| `response_http_status` | Outbound response status                                                      |
| `response_payload`     | Truncated response body                                                       |
| `attempt`              | Number of processing attempts                                                 |
| `processing_at`        | Timestamp when execution starts                                               |
| `completed_at`         | Timestamp when lifecycle ends (success/failure/cancel)                        |
| `created_at`           | Capture timestamp                                                             |
| `updated_at`           | State change timestamp                                                        |

Inbound-specific rules → see **Receive Webhook Relay PRD**  
Outbound-specific rules → see **Send Webhook Relay PRD**

---

# 3. Failure Reason Enum (Complete Spec)

Atlas Relay defines a unified failure enum used across inbound/outbound flows.  
This list must remain complete and consistent across PRDs.

| Code | Label                 | Meaning                                       |
|------|-----------------------|-----------------------------------------------|
| 100  | EXCEPTION             | Uncaught exception inside event/job execution |
| 101  | PAYLOAD_TOO_LARGE     | Payload exceeded configured max bytes         |
| 102  | NO_ROUTE_MATCH        | Reserved for legacy routing logic             |
| 103  | CANCELLED             | Relay manually cancelled                      |
| 104  | ROUTE_TIMEOUT         | Consumer-determined processing timeout        |
| 105  | INVALID_PAYLOAD       | Malformed JSON or decode error                |
| 108  | INVALID_GUARD_HEADERS | Inbound guard header validation failed        |
| 109  | INVALID_GUARD_PAYLOAD | Inbound guard payload validation failed       |
| 201  | HTTP_ERROR            | Non-2xx response                              |
| 205  | CONNECTION_ERROR      | Network/DNS/SSL failure                       |
| 206  | CONNECTION_TIMEOUT    | HTTP timeout                                  |

Inbound PRD links directly to this table for all failure codes it references.

---

# 4. Relay Types

| Enum       | Meaning                                                    |
|------------|------------------------------------------------------------|
| `INBOUND`  | Captured from an inbound HTTP request (`Relay::request()`) |
| `OUTBOUND` | Created when sending a webhook using `Relay::http()`       |
| `RELAY`    | Internal/system relay                                      |

Relay type inference:
- `request()` → INBOUND
- `http()` → OUTBOUND
- `payload()` → RELAY unless overwritten

---

# 5. Status Lifecycle Rules

Universal rules applying to all relay types:

| Status       | Meaning                                   |
|--------------|-------------------------------------------|
| `QUEUED`     | Relay created but not executed            |
| `PROCESSING` | Execution started (event, job, HTTP)      |
| `COMPLETED`  | Execution finished successfully           |
| `FAILED`     | Execution finished with failure_reason    |
| `CANCELLED`  | Explicit cancellation (`Relay::cancel()`) |

Transitions:
- `QUEUED → PROCESSING` when job/event/HTTP begins
- `PROCESSING → COMPLETED` on success
- `PROCESSING → FAILED` on exception/HTTP failure/etc.
- `PROCESSING → CANCELLED` on manual cancel
- `completed_at` always set for Completed/Failed/Cancelled

---

# 6. Capture Requirements

Unified capture rules:

- Apply payload + header normalization
- Mask sensitive headers
- Truncate payloads respecting `payload_max_bytes`
- Persist **before** execution
- Always record:
    - method
    - url
    - headers
    - payload
    - type
    - status
    - timestamps

Inbound capture details → **Receive Webhook Relay PRD**  
Outbound capture details → **Send Webhook Relay PRD**

---

# 7. Delivery Requirements

Atlas supports three delivery modes:

## 7.1 Event Execution
- Synchronous execution
- Exceptions → FAILED + EXCEPTION failure_reason
- Completes relay immediately

## 7.2 Job Dispatch
- Uses Laravel’s native job dispatcher
- Middleware ensures lifecycle tracking
- Exceptions → FAILED
- Supports chains (Bus::chain)

## 7.3 HTTP Delivery
- Uses Laravel `Http` client directly
- Records:
    - response status
    - truncated response body
- Exceptions map to HTTP-related RelayFailure codes

Outbound delivery details → **Send Webhook Relay PRD**

---

# 8. Archiving (Retention Requirements)

Full behavior defined in:  
**[Archiving & Logging](./Archiving-and-Logging.md)**

Key requirements:
- Archive relays after configured days
- Purge archives after retention period
- Archive tables mirror schema exactly

---

# 9. Cross‑PRD Linking (Canonical References)

- Inbound rules → **[Receive Webhook Relay](./Receive-Webhook-Relay.md)**
- Outbound rules → **[Send Webhook Relay](./Send-Webhook-Relay.md)**
- Usage examples → **[Example Usage](./Example-Usage.md)**
- System retention → **[Archiving & Logging](./Archiving-and-Logging.md)**

---

This document is the **source of truth** for all relay fields, lifecycle rules, and failure codes.  
All sub‑PRDs must reference this document for schema and failure semantics.
