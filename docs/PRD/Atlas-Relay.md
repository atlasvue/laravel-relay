# PRD — Atlas Relay

Atlas Relay defines the unified system for capturing, processing, delivering, and archiving all relay types within the package, serving as the authoritative specification for lifecycle rules, data structures, and failure semantics.

## Table of Contents
- [Relay Data Model](#relay-data-model)
- [Failure Reason Enum](#failure-reason-enum)
- [Relay Types](#relay-types)
- [Status Lifecycle Rules](#status-lifecycle-rules)
- [Capture Requirements](#capture-requirements)
- [Delivery Requirements](#delivery-requirements)
- [Archiving (Retention Requirements)](#archiving-retention-requirements)

## Relay Data Model

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

## Failure Reason Enum

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

## Relay Types

| Enum       | Meaning                                                    |
|------------|------------------------------------------------------------|
| `INBOUND`  | Captured from an inbound HTTP request (`Relay::request()`) |
| `OUTBOUND` | Created when sending a webhook using `Relay::http()`       |
| `RELAY`    | Internal/system relay                                      |

Relay type inference:
- `request()` → INBOUND
- `http()` → OUTBOUND
- `payload()` → RELAY unless overwritten

## Status Lifecycle Rules

Universal rules applying to all relay types:

| Status       | Meaning                                   |
|--------------|-------------------------------------------|
| `QUEUED`     | Relay created but not executed            |
| `PROCESSING` | Execution started (event, job, HTTP)      |
| `COMPLETED`  | Execution finished successfully           |
| `FAILED`     | Execution finished with failure_reason    |
| `CANCELLED`  | Explicit cancellation (`Relay::cancel()`) |

## Capture Requirements

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

## Delivery Requirements

Atlas supports three delivery modes:

### Event Execution
- Synchronous execution
- Exceptions → FAILED + EXCEPTION failure_reason
- Completes relay immediately

### Job Dispatch
- Uses Laravel’s native job dispatcher
- Middleware ensures lifecycle tracking
- Exceptions → FAILED
- Supports chains (Bus::chain)

### HTTP Delivery
- Uses Laravel `Http` client directly
  See **[Send Webhook Relay](./Send-Webhook-Relay.md)** for full outbound send behavior.
- Records:
    - response status
    - truncated response body
- Exceptions map to HTTP-related RelayFailure codes

## Archiving (Retention Requirements)

Full behavior defined in: **[Archiving & Logging](./Archiving-and-Logging.md)**

Key requirements:
- Archive relays after configured days
- Purge archives after retention period
- Archive tables mirror schema exactly

## Also See
- [Receive Webhook Relay](./Receive-Webhook-Relay.md)
- [Send Webhook Relay](./Send-Webhook-Relay.md)
- [Archiving & Logging](./Archiving-and-Logging.md)
- [Example Usage](./Example-Usage.md)