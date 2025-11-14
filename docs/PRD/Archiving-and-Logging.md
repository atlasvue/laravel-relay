# PRD — Archiving & Logging

Atlas Relay defines a unified retention and archival model that preserves full relay history while keeping the live relay table lean and performant.

## Table of Contents
- [Overview](#overview)
- [Goals](#goals)
- [Lifecycle Summary](#lifecycle-summary)
- [Data Model Requirements](#data-model-requirements)
- [Archiving Process Requirements](#archiving-process-requirements)
- [Purge Process Requirements](#purge-process-requirements)
- [Scheduling Requirements](#scheduling-requirements)
- [Observability Requirements](#observability-requirements)

## Overview
Atlas Relay uses a two-table retention system:

- `atlas_relays` — live active relay records
- `atlas_relay_archives` — immutable historical storage

All lifecycle fields—status, timestamps, failure details—are stored **inline**, eliminating the need for log tables. Archiving moves Completed/Failed relays from the live table to the archive table based on retention rules.

## Goals
- Maintain a lean, performant live relay table
- Preserve full historical auditability
- Ensure archive schema mirrors live schema exactly
- Support predictable retention windows (archive → purge)
- Avoid partial or inconsistent archive states

## Lifecycle Summary
Relay Created → Inline Updates → Completed/Failed/Cancelled → Archived → Purged

Key rule: `completed_at` determines archival eligibility.

## Data Model Requirements
### Live Table — `atlas_relays`
Contains all fields defined in the **Atlas Relay** PRD.

### Archive Table — `atlas_relay_archives`
- Mirrors the live table schema exactly
- Adds a required `archived_at` timestamp
- Records become immutable once archived

### No Log Tables
All lifecycle details must be stored inline:
- `failure_reason`
- `response_http_status`
- `response_payload` (truncated)
- `processing_at`, `completed_at`
- `meta`

## Archiving Process Requirements
Archiving typically runs daily.

Steps:
1. Select relays where `completed_at` exceeds `ATLAS_RELAY_ARCHIVE_DAYS`
2. Copy records into `atlas_relay_archives`
3. Apply `archived_at` timestamp
4. Verify batch integrity
5. Delete original records within the same transaction
6. Continue until no eligible relays remain

Batch Controls:
```
php artisan atlas-relay:archive --chunk=500
```

## Purge Process Requirements
Purging removes archived data older than `ATLAS_RELAY_PURGE_DAYS`.

Rules:
- Deletes records based on `archived_at`
- Must be safe to resume if interrupted
- Uses transactions to avoid partial purges

Default values:
- Archive: 30 days
- Purge: 180 days

## Scheduling Requirements

```php
Schedule::command('atlas-relay:archive')->dailyAt('22:00');
Schedule::command('atlas-relay:purge-archives')->dailyAt('23:00');
```

## Observability Requirements
Inline relay fields provide all metrics for analysis, including:
- status
- failure_reason
- meta
- attempt counters
- lifecycle timestamps

External logging systems may be used for deep analytics.

## Also See
- [Atlas Relay](./Atlas-Relay.md)
- [Receive Webhook Relay](./Receive-Webhook-Relay.md)
- [Send Webhook Relay](./Send-Webhook-Relay.md)
- [Example Usage](./Example-Usage.md)
