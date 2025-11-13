
# PRD — Archiving & Logging

## Overview
Archiving preserves historical relay data without dedicated log tables. All lifecycle details are stored inline on `atlas_relays`, which is the single source of truth. Older records are migrated to `atlas_relay_archives` for long‑term retention and performance.

---

## Goals
- Remove log tables; keep schema lean.
- Maintain full auditability using inline lifecycle fields.
- Archive and purge records automatically.
- Keep live tables fast while retaining complete history.
- Ensure archive table mirrors `atlas_relays` for 1:1 migration.

---

## Lifecycle Summary
Relay Created → Inline Updates → Completed/Failed → Archived → Purged

---

## Data Model

### Source of Truth
- `atlas_relays` contains all lifecycle metadata: status, failure_reason, response_status/payload, timing fields, attempts, retry/delay/timeout config, `retry_at`, etc.
- No separate log tables.
- Archived records are exact copies.

### Archive Table
- `atlas_relay_archives` mirrors `atlas_relays` exactly.
- Used for audits, analytics, and historical queries.
- Records are immutable.

---

## Archiving Process
Ran daily:

1. Select relays older than `ATLAS_RELAY_ARCHIVE_DAYS`.
2. Copy to archive in batches (`ATLAS_RELAY_ARCHIVE_CHUNK_SIZE`, default 500).
3. Verify integrity (counts/checksums).
4. Delete originals in the same transaction.
5. Continue until all eligible records are moved.

---

## Purge Process
Ran nightly after archiving:

- Delete archive records older than `ATLAS_RELAY_PURGE_DAYS`.
- Fully transactional and resumable.

---

## Scheduling
- Archiving: **10 PM EST** daily.
- Purging: **11 PM EST** daily.
- Times converted to UTC internally.

---

## Configuration
| Variable                         | Default | Description                     |
|----------------------------------|---------|---------------------------------|
| `ATLAS_RELAY_ARCHIVE_DAYS`       | 30      | Age threshold to archive        |
| `ATLAS_RELAY_PURGE_DAYS`         | 180     | Age threshold to purge archives |
| `ATLAS_RELAY_ARCHIVE_CHUNK_SIZE` | 500     | Batch size for migration        |

---

## Observability
Inline fields provide all required metrics:

- `status`, `failure_reason`, `response_status`, `response_payload` (truncated)
- Attempt counts, retry/delay/timeout metadata
- `retry_at`, timestamps, duration fields

Derived metrics for operational reporting:
- `relay_archived_count`
- `relay_purge_count`
- `relay_archive_duration`

For deeper analytics (e.g., per-attempt traces), use external log sinks (S3/CloudWatch/Elasticsearch).

---

## Error Handling
- Failures logged through system-level application logs.
- Chunked, resumable operations protect against partial failures.
- Deletes occur only after verified archival copy.

---

## Dependencies & Integration
- Depends on payload capture and outbound delivery lifecycle fields.
- Integrates with automation jobs that update inline metadata used by archive filters.

---

## Notes
- No database log tables exist.
- Archive schema must always match `atlas_relays`.
- External logging optional for richer diagnostics.

---

## Outstanding Questions
- Which inline lifecycle fields are the minimum required for production observability?
- Should aging be based on `updated_at` instead of `created_at`?
- Do we need a restoration tool to move archived relays back into live storage?
