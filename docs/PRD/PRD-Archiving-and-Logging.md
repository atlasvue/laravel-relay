# PRD — Archiving & Logging

## Overview

The **Archiving & Logging** module defines how Atlas Relay preserves historical data for auditing and observability while maintaining system performance through automatic cleanup and long-term storage policies.

This module consolidates **logging** (the detailed record of all relay activities and state transitions) and **archiving** (the movement of completed or failed relay records into cold storage). Together, they ensure Atlas Relay remains both auditable and performant under continuous operation.

---

## Goals

* Preserve complete historical logs and relay data for traceability.
* Automatically archive aged relay and log records to maintain database performance.
* Support configurable retention and purge schedules.
* Ensure archived data mirrors live data for schema consistency and integrity.
* Enable efficient querying of both live and archived records for analytics and audit.

---

## Functional Summary

**Relay Created → Logged Throughout Lifecycle → Completed or Failed → Archived → Purged (after retention)**

---

## Logging System

### 1. Log Purpose

All relay activity, including state changes, outbound attempts, retries, and failures, must be captured as immutable logs.

### 2. Log Storage

Logs are persisted in `atlas_relay_logs`, with a 1-to-many relationship to `atlas_relays`.

| Field        | Description                                                                   |
|--------------|-------------------------------------------------------------------------------|
| `id`         | Unique log record ID.                                                         |
| `relay_id`   | Linked relay record ID.                                                       |
| `stage`      | Lifecycle stage (capture, routing, delivery, retry, etc.).                    |
| `action`     | Operation performed (e.g., `HTTP_POST`, `EVENT_DISPATCH`, `RETRY_TRIGGERED`). |
| `status`     | Outcome of the action (`success`, `failed`, `timeout`, etc.).                 |
| `message`    | Summary of the event or result.                                               |
| `metadata`   | JSON details (response payloads, headers, duration, etc.).                    |
| `created_at` | Timestamp of log entry.                                                       |

### 3. Logging Behavior

* Every state transition and action generates at least one log record.
* Logs are immutable and append-only.
* Each relay has a full chronological trail of activity.
* Logging failures never block relay progression.
* System logs are retained in active storage for quick access until archived.

### 4. Log Querying

* Logs can be filtered by relay ID, status, stage, or time range.
* Recent logs remain in active tables for fast lookup.
* Archived logs are retrievable from the archive tables for historical reporting.

---

## Archiving System

### 1. Purpose

Archiving prevents data bloat by moving completed or failed relays (and associated logs) into dedicated archive tables after a defined retention window.

### 2. Archive Tables

**Schema Parity Update:**

Archive tables must include all relay configuration fields to maintain full data fidelity.
The following configuration columns must exist in both active and archive schemas:
* `is_retry`
* `retry_seconds`
* `retry_max_attempts`
* `is_delay`
* `delay_seconds`
* `timeout_seconds`
* `http_timeout_seconds`

Archive tables mirror live schemas to ensure direct record mapping.

| Archive Table          | Description                                                      |
|------------------------|------------------------------------------------------------------|
| `atlas_relay_archives` | Stores archived relay records, including outbound delivery data. |

### 3. Archive Process

Archiving is handled by scheduled automation jobs:

* Select relays older than `ATLAS_RELAY_ARCHIVE_DAYS`.
* Copy relay and corresponding logs to archive tables.
* Delete successfully archived records from live tables.
* Confirm transaction integrity before deletion.
* Process in chunks (default: 500 per batch).

### 4. Purge Process

Purging removes archived data after long-term retention.

* Occurs nightly after archiving completes.
* Deletes archive records older than `ATLAS_RELAY_PURGE_DAYS`.
* Ensures both relay and log archives are purged consistently.
* Logs all purged records for operational traceability.

### 5. Scheduling

| Job           | Default Time (EST) | Frequency |
|---------------|--------------------|-----------|
| **Archiving** | 10:00 PM           | Daily     |
| **Purging**   | 11:00 PM           | Daily     |

Times are converted to UTC internally and may be adjusted via environment configuration.

---

## Configuration

| Variable                         | Default | Description                                       |
|----------------------------------|---------|---------------------------------------------------|
| `ATLAS_RELAY_ARCHIVE_DAYS`       | 30      | Days before relays and logs are archived.         |
| `ATLAS_RELAY_PURGE_DAYS`         | 180     | Days before archived data is permanently deleted. |
| `ATLAS_RELAY_ARCHIVE_CHUNK_SIZE` | 500     | Number of records processed per batch.            |

---

## Observability & Reporting

Archiving and logging both feed into observability dashboards for performance and auditing.

| Metric                   | Description                                     |
|--------------------------|-------------------------------------------------|
| `relay_log_count`        | Total logs generated.                           |
| `relay_archived_count`   | Total archived relay records.                   |
| `relay_purge_count`      | Total purged records during last cycle.         |
| `relay_archive_duration` | Average time taken for archive batch execution. |

Reports can be exported for compliance or performance tracking.

---

## Error Handling

* Archive and purge failures are logged as `SYSTEM_EXCEPTION` events.
* Partial archives resume from the last checkpoint on next run.
* Duplicate or missing records are validated using hash checksums.
* Archive operations are transactional—deletion occurs only after verified copy.

---

## Dependencies & Integration

* **Depends on:** [PRD — Payload Capture](./PRD-Payload-Capture.md), [PRD — Routing](./PRD-Routing.md)
* **Integrates with:** [PRD — Atlas Relay](./PRD-Atlas-Relay.md) lifecycle and job automation

---

## Notes

* Active and archived logs maintain identical structure for easy migration.
* Archiving ensures that Atlas Relay databases remain performant at scale.
* Archived records are immutable; restoration is possible via import utilities.
* Logs can be stored in both database and optional external log sinks (e.g., S3, Elasticsearch).

---

## Outstanding Questions / Clarifications

* Should archive logs be compressed or left in raw JSON for queryability?
* Should external log sink support (e.g., CloudWatch, Loki) be part of core or separate extension?
* Should purge operations support soft-delete with delayed permanent deletion?

---

### See Also
* [PRD — Atlas Relay](./PRD-Atlas-Relay.md)
* [PRD — Payload Capture](./PRD-Payload-Capture.md)
* [PRD — Routing](./PRD-Routing.md)
* [PRD — Outbound Delivery](./PRD-Outbound-Delivery.md)
* [PRD — Archiving & Logging](./PRD-Archiving-and-Logging.md)
* [PRD — Example Usage](./PRD-Example-Usage.md)
