# Contributing to Atlas Relay

Atlas Relay is a collection of Composer-installable Laravel packages. Every contribution must respect the repository
guardrails defined in [AGENTS.md](./AGENTS.md) and the Product Requirement Documents (PRDs) under `docs/PRD`. Those artifacts are
the single source of truth for naming, data structures, control flow, and business rules—never diverge from them.

---

## Core Expectations

- **Read [AGENTS.md](./AGENTS.md) first.** It details structure, naming, strict types, doc block requirements, and other conventions.
- **Reference the applicable PRD** before touching any class or service. If the PRD contradicts an existing pattern,
  follow the PRD and flag the discrepancy in your PR/issue.
- **Keep the package application-agnostic.** Do not couple code to a consuming Laravel app’s specifics.
- **Document every class** with a PHPDoc header summarizing its purpose and noting relevant PRDs.

---

## Product Requirement Documentation (PRD) Index

Every change must align with the PRDs stored under `docs/PRD`. Use the table below to locate the authoritative spec for
each subsystem:

| PRD | Scope |
| --- | --- |
| [PRD-Atlas-Relay](./docs/PRD/PRD-Atlas-Relay.md) | End-to-end lifecycle overview, automation cadence, and domain terminology. |
| [PRD-Payload-Capture](./docs/PRD/PRD-Payload-Capture.md) | Request ingestion, validation, storage schema, and capture constraints. |
| [PRD-Routing](./docs/PRD/PRD-Routing.md) | Auto-routing configuration, providers, caching, and failure handling. |
| [PRD-Outbound-Delivery](./docs/PRD/PRD-Outbound-Delivery.md) | HTTP/event/dispatch delivery modes, retries, and timeout semantics. |
| [PRD-Archiving-and-Logging](./docs/PRD/PRD-Archiving-and-Logging.md) | Retention, archive schema, purge automation, and observability mandates. |
| [PRD-Example-Usage](./docs/PRD/PRD-Example-Usage.md) | Canonical developer workflows and API usage patterns. |

Always cite the relevant PRD(s) in code comments and pull request descriptions when implementing or modifying behavior.

---

## Required Local Validation

All contributors **must** run the following commands locally before opening a pull request. If you intentionally skip a
command (rare), explain the reason in the PR description.

| Command | Purpose |
| --- | --- |
| `./vendor/bin/pint` | Enforces PSR-12 and the repository Pint preset. |
| `./vendor/bin/phpstan --debug` | Static analysis at level 8. The `--debug` flag avoids socket restrictions in some environments. |
| `composer test` | Executes the test suite (PHPUnit/Pest). Add or update tests for every behavior change. |

Only submit your work once all three commands succeed.

---

## Branching Strategy & Commits

### Branching

We follow a trunk-based development approach with short-lived feature branches:

- `main` is the primary branch and must remain deployable.
- Feature branches: `feature/description-of-change`
- Bug fix branches: `fix/description-of-bug`
- Release branches: `release/version-number`

Always branch from `main`, keep branches focused, and prefer rebasing over merging to maintain a clean history.

### Commit Messages

Atlas Relay adopts **Conventional Commits 1.0.0** for consistency and tooling compatibility.

```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

Accepted types include:

- `feat` – new feature
- `fix` – bug fix
- `docs` – documentation-only change
- `style` – formatting changes (no behavior impact)
- `refactor` – behavior-preserving code change
- `perf` – performance improvement
- `test` – adding or updating automated tests
- `chore` – tooling or build process adjustments

Keep commits scoped, reference issues/PRDs in the body or footer, and avoid mixing unrelated work.

---

## Submission Checklist

1. Create a branch following the naming rules above.
2. Implement changes in alignment with the relevant PRDs and `AGENTS.md`.
3. Ensure every class retains/receives the required PHPDoc header.
4. Run Pint, PHPStan (`--debug`), and the full test suite.
5. Update documentation (README, PRDs, etc.) when behavior or usage changes.
6. Open a pull request summarizing the change and validation steps.

Thank you for helping keep Atlas Relay reliable, observable, and PRD-aligned!
