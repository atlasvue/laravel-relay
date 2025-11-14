# PRD — Archiving & Logging

## Purpose
This PRD defines the **retention and archival requirements** for Atlas Relay.  
It ensures long‑term auditability without log tables and guarantees that the live relay table remains lean and performant.

This document applies to **all relay types** (inbound, outbound, internal).  
For the unified relay schema, see:  
**[PRD — Atlas Relay](./PRD-Atlas-Relay.md#2-relay-data-model-full-field-specification)**

---

# 1. Overview

Atlas Relay uses a **two‑table architecture**:

1. `atlas_relays` — the live table
2. `atlas_relay_archives` — historical storage

All lifecycle fields are stored **inline** on the relay record.  
No separate logs table exists.

Archiving moves completed/failed relays from the live table into the archive table based on retention rules.

---

# 2. Goals

- Maintain a **lean live table**
- Preserve **full auditability** with no loss of data
- Ensure archive table is a **1:1 mirror** of the live table schema
- Provide **predictable retention windows** (archive → purge)
- Avoid partial archiving via transactional batch operations

---

# 3. Lifecycle Summary

**Relay Created → Inline Updates → Completed/Failed/Cancelled → Archived → Purged**

- `completed_at` is the authoritative timestamp for determining archival eligibility.
- Retention logic must use lifecycle timestamps stored directly on the relay.

---

# 4. Data Model Requirements

### 4.1 Live Table: `atlas_relays`
The live table contains **all fields** listed in the Atlas Relay PRD:  
**[Full Relay Field Spec](./PRD-Atlas-Relay.md#2-relay-data-model-full-field-specification)**

### 4.2 Archive Table: `atlas_relay_archives`
- Must mirror **every column** from `atlas_relays`, including enums and casts.
- Adds one additional field:
    - `archived_at` — timestamp when moved into archives.
- Records in archives are **immutable**.

### 4.3 No Log Tables
All lifecycle detail must be inside:
- `failure_reason`
- `response_http_status`
- `response_payload` (truncated)
- `processing_at`, `completed_at`
- inline metadata (`meta`)

This removes log table overhead and ensures consistent storage.

---

# 5. Archiving Process Requirements

Archiving is typically run daily.

### Steps:
1. Select relays where:
    - `completed_at` is older than `ATLAS_RELAY_ARCHIVE_DAYS`
2. Copy the records to `atlas_relay_archives`
3. Stamp `archived_at`
4. Verify batch integrity (count check)
5. Delete originals in the **same transaction**
6. Continue until no eligible records remain

### Batch Controls
`php artisan atlas-relay:archive --chunk=500`
- Default chunk = 500
- Must support chunk override for high‑volume environments

---

# 6. Purge Process Requirements

Purging clears out aged archives.

### Rules:
- Delete archive records where `archived_at` is older than `ATLAS_RELAY_PURGE_DAYS`
- Must be transactional and resume safely after interruptions

By default:
- Archive: 30 days
- Purge: 180 days

Values defined in `config/atlas-relay.php`.

---

# 7. Scheduling Requirements

Recommended defaults (configurable):

```php
Schedule::command('atlas-relay:archive')->dailyAt('22:00');
Schedule::command('atlas-relay:purge-archives')->dailyAt('23:00');
```

Any schedule is acceptable as long as:
- Archiving runs before purge
- Archive and purge never run in parallel

---

# 8. Observability Requirements

Inline lifecycle fields must expose everything needed for debugging and analytics:

- `status`
- `failure_reason`
- `meta`
- `response_http_status`
- `response_payload`
- `processing_at`
- `completed_at`
- attempt counters
- duration fields (when tracked)

Derived metrics (not stored, computed by consumers):
- archive throughput
- purge throughput
- average response status distribution

For deep operational analytics (attempt‑level logs), external logging may be used.

---

# 9. Failure Handling

- All archiving/purging operations must be **resumable**
- Archive/delete must occur **only** after verified copy
- On failure, system logs should capture:
    - batch range
    - error summary
    - last successful ID

Partial archive states must never occur.

---

# 10. Integration Requirements

- Archiving depends on accurate lifecycle fields stored during capture and execution
- Works identically for INBOUND, OUTBOUND, and RELAY types
- Consumers may add their own reporting on top of archive data

---

# 11. PRD Cross‑Links

- Unified system spec → **[PRD — Atlas Relay](./PRD-Atlas-Relay.md)**
- Inbound capture rules → **[PRD — Receive Webhook Relay](./PRD-Receive-Webhook-Relay.md)**
- Outbound send rules → **[PRD — Send Webhook Relay](./PRD-Send-Webhook-Relay.md)**
- Usage examples → **[PRD — Example Usage](./PRD-Example-Usage.md)**

---

This PRD defines the complete retention and archiving contract for Atlas Relay.  
All implementations must conform to these lifecycle and data model requirements.
