# Contributing to Atlas Relay

This document defines the **minimum checks** and **commit style** required to complete any contribution.  
All coding standards, architecture rules, and naming conventions live in **[AGENTS.md](./AGENTS.md)**.

---

## Product Requirement Documentation (PRD) Index

Every change must align with the PRDs stored under `docs/PRD`. Use the table below to locate the authoritative spec for
each subsystem:

| PRD                                                                  | Scope                                                                      |
|----------------------------------------------------------------------|----------------------------------------------------------------------------|
| [Atlas Relay](./docs/PRD/Atlas-Relay.md)                     | End-to-end lifecycle overview, automation cadence, and domain terminology. |
| [Receive Webhook Relay](./docs/PRD/Receive-Webhook-Relay.md) | Request ingestion, validation, guard handling, and capture constraints.    |
| [Send Webhook Relay](./docs/PRD/Send-Webhook-Relay.md)       | HTTP/event/dispatch delivery paths, lifecycle recording, and safety rails. |
| [Archiving & Logging](./docs/PRD/Archiving-and-Logging.md)   | Retention, archive schema, purge automation, and observability mandates.   |
| [Example Usage](./docs/PRD/Example-Usage.md)                 | Canonical developer workflows and API usage patterns.                      |

Always cite the relevant PRD(s) in code comments and pull request descriptions when implementing or modifying behavior.

---

## Required Validation

Run all three commands and ensure **zero errors**:

```bash
./vendor/bin/pint
./vendor/bin/phpstan --debug
composer test
```

**Definition of Done**
- Pint: no pending diffs after running.
- PHPStan: level 8 with 0 errors.
- Tests: all pass deterministically (no retries).

If any check fails, the work is **not complete**.

---

## Commit Style

Use **Conventional Commits 1.0.0**:

```
<type>[optional scope]: <short description>

[optional body]

[optional footer(s)]
```

Common types:
- `feat` — new feature
- `fix` — bug fix
- `docs` — documentation only
- `style` — formatting, no behavior change
- `refactor` — behavior‑preserving code change
- `perf` — performance improvement
- `test` — add/update tests
- `chore` — tooling/build changes

Keep commits focused and descriptive.
