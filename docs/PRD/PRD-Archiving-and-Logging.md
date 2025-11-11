# PRD — Archiving & Logging

## Overview

The **Archiving** module defines how Atlas Relay preserves historical data for auditing and observability **without separate log tables**. All lifecycle details are stored **inline on `atlas_relays`** and migrated to an archive table for long‑term retention and performance.

This module removes the dedicated log and log‑archive tables. The **relay record is the single source of truth** for state, outcomes, timing, attempts, and failure reasons. Archiving moves completed/failed relay records out of hot storage on a schedule to keep primary tables fast.

---

## Goals

- Keep the schema lean and high‑performance by **eliminating log tables**.
- Preserve auditability via **inline lifecycle metadata** on `atlas_relays`.
- Automatically archive aged relay records and purge old archives.
- Maintain **schema parity** between live and archive relay records for simple migration.
- Provide efficient querying of recent activity (live) and historical activity (archive).

---

## Functional Summary

**Relay Created → Lifecycle Metadata Updated Inline → Completed/Failed → Archived → Purged (after retention)**

---

## Data Model

### Source of Truth

- **`atlas_relays`** is authoritative for **all lifecycle details** (status transitions, response status/payload, timing, attempt/retry counts, failure_reason, retry/delay/timeout configuration, `retry_at`, etc.).
- No separate log tables exist.
- Historical records are copied 1:1 to the archive table.

### Archive Table

- **`atlas_relay_archives`** — mirrors the **entire** `atlas_relays` schema so records can be moved without transformation.
- Archive records are immutable and intended for long‑term analytics, audits, and reporting.

> Note: Any lifecycle fields required for observability must live on `atlas_relays` (and therefore on `atlas_relay_archives`).

---

## Archiving Process

Performed by a scheduled job:

1. Select relay records older than `ATLAS_RELAY_ARCHIVE_DAYS` (by `created_at` or `updated_at` as defined by implementation).
2. Copy selected relays into `atlas_relay_archives` in **chunked batches** (default: 500).
3. Verify copy integrity (row counts/hash checksums as applicable).
4. **Delete** successfully copied relays from `atlas_relays` within the same transaction boundary to avoid duplicates.
5. Continue until no more eligible records remain.

---

## Purge Process

Executed nightly **after** archiving:

- Delete `atlas_relay_archives` records older than `ATLAS_RELAY_PURGE_DAYS`.
- Operations are transactional and resumable (idempotent on re‑run).

---

## Scheduling

- **Archiving:** Daily at **10:00 PM EST**.
- **Purging:** Daily at **11:00 PM EST**.

Times are converted to UTC internally and can be adjusted via environment variables.

---

## Configuration

- `ATLAS_RELAY_ARCHIVE_DAYS` (default **30**): Age threshold before archiving.
- `ATLAS_RELAY_PURGE_DAYS` (default **180**): Age threshold before purge of archived records.
- `ATLAS_RELAY_ARCHIVE_CHUNK_SIZE` (default **500**): Batch size for archive moves.

---

## Observability

With log tables removed, observability relies on inline relay fields and derived metrics:

- Inline fields on `atlas_relays` (e.g., `status`, `failure_reason`, `response_status`, `response_payload` [truncated], attempt/retry counters, timing/duration fields, `retry_at`, etc.).
- Roll‑up metrics derived from counts and timestamps:
    - `relay_archived_count` — total archived in last cycle.
    - `relay_purge_count` — total purged in last cycle.
    - `relay_archive_duration` — average processing time per batch.

> If additional analytics are desired (e.g., per‑attempt traces), emit structured **application logs** to external sinks (S3/CloudWatch/Elasticsearch) outside the database path.

---

## Error Handling

- Archive/purge failures are recorded as **system events** (application logging) and surfaced via operational alerts/metrics.
- Processes are chunked and resumable; partial batches continue on the next run.
- Integrity checks ensure deletion occurs **only after** a verified copy to archive.

---

## Dependencies & Integration

- **Depends on:** Payload capture and outbound delivery to populate lifecycle fields on `atlas_relays`.
- **Integrates with:** Atlas Relay automation jobs (retry, stuck requeue, timeout) that update inline lifecycle state used by archiving filters.

---

## Notes

- No database log tables exist in this design.
- The archive table mirrors `atlas_relays` exactly to keep migrations simple and reliable.
- External log sinks remain optional for deep diagnostics without impacting DB performance.

---

## Outstanding Questions / Clarifications

- Which fields on `atlas_relays` are considered **minimum required** for inline observability in production environments (e.g., explicit attempt counters, first/last attempt timestamps)?
- Should archiving select by `updated_at` (lifecycle completion time) rather than `created_at` for more precise aging?
- Do we need a restoration utility (archive → live) for incident replay scenarios?
