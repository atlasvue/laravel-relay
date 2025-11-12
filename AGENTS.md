# Agents

This guide defines the conventions and best practices for contributors working on this **Laravel package repository**. These rules ensure consistency, clarity, and compatibility for all consumers installing this package via Composer.

> For validation and commit requirements, see **[CONTRIBUTING.md](./CONTRIBUTING.md)**.

---

## Purpose

This repository provides **standalone Laravel packages** designed for installation in other Laravel applications. There is **no full Laravel app** in this repo — all logic must remain **framework-integrated but package-isolated**.

All **Agents** must treat any **Product Requirement Documents (PRDs)** included in the project as the **absolute source of truth** for functionality, naming, structure, and business logic.

> **PRDs override all assumptions or prior conventions.** When a PRD defines behavior, data flow, or naming, Agents must implement code that directly matches those definitions. If uncertainty arises, Agents must defer to the PRD or seek clarification before coding.

---

## Core Principles

1. Follow **PSR-12** and **Laravel Pint** formatting.
2. Use **strict types** and modern **PHP 8.2+** syntax.
3. All code must be **stateless**, **framework-aware**, and **application-agnostic**.
4. Keep everything **self-contained**: no hard dependencies on a consuming app.
5. Always reference **PRDs** for functional requirements and naming accuracy.
6. Write clear, testable, and deterministic code.
7. Never introduce new logic, naming, or assumptions that conflict with the PRD.
8. Every class must include a **PHPDoc block at the top of the file** summarizing its purpose, expected usage, and any relevant PRD reference. These doc blocks are mandatory and intended to help both internal and external consumers understand the class role without reading its internals.

Example:

```php
/**
 * Class UserWebhookService
 *
 * Handles webhook registration, processing, and retry logic for user-related events.
 * Defined by PRD: WebhookStation — Outbound Delivery Rules.
 */
```

---

## Structure

Each package should follow this layout:

```
package-name/
├── composer.json
├── src/
│   ├── Providers/
│   │   └── PackageServiceProvider.php
│   ├── Services/
│   ├── Models/ (if applicable)
│   ├── Contracts/
│   ├── Exceptions/
│   ├── Support/
│   └── Singletons/ (optional)
├── config/ (optional)
├── database/ (optional, migrations/factories)
├── tests/
└── README.md
```

---

## Naming & Conventions

### Class Naming

* **Service Providers:** PascalCase + `ServiceProvider` suffix.
* **Services:** PascalCase + `Service` suffix.
* **Singletons:** PascalCase + `Singleton` suffix (for reusable, shared instances).
* **Contracts:** Interface files end with `Interface`.
* **Models:** Singular, PascalCase (if present).
* **Enums:** PascalCase with clear scope.
* **Exceptions:** PascalCase + `Exception` suffix.

### File & Namespace Structure

* All PHP classes must use the package namespace root (e.g. `Vendor\\PackageName\\...`).
* Group by domain when applicable (`Services/Users/UserService.php`).
* Avoid mixing unrelated logic within a single directory.

### Variables & Methods

* Use `camelCase` for variables and methods.
* Prefix booleans with `is`, `has`, or `can`.
* Keep methods short, descriptive, and predictable.
* Avoid ambiguous names (`handleData()` → `parseWebhookPayload()`).
* Ensure method and service names match **PRD-defined terminology** when applicable.

---

## Service Provider Rules

* Must handle **registration**, **publishing**, and **booting** cleanly.
* Register bindings, configs, routes, and migrations **only if required**.
* Use **package auto-discovery**.
* Keep provider logic minimal and avoid business logic.

---

## Code Practices

1. **Business Logic** — belongs in `Services/` or dedicated singleton classes, not controllers or providers.
2. **Configuration** — define publishable config files in `config/`, use sensible defaults.
3. **Testing** — use PHPUnit or Pest; cover both happy and failure paths.
4. **Type Safety** — declare all parameter and return types.
5. **Error Handling** — use custom exceptions for expected failures.
6. **Dependencies** — keep minimal; prefer Laravel contracts over concrete bindings.
7. **PRD Alignment** — always verify that logic, method names, and service behavior align with the PRD before implementation.
8. **Deviation Handling** — if any PRD rule appears incomplete or conflicting, pause work and flag it for clarification rather than guessing.
9. **Documentation via Doc Blocks** — every class, interface, and trait must include a top-level PHPDoc block explaining its purpose and referencing the PRD section (if applicable). This ensures consumers understand intent and maintainability is preserved.

---

## Documentation

Each package must include:

* `README.md` — Installation, Configuration, Usage, and Examples.
* `CHANGELOG.md` — Noting versioned updates.
* `LICENSE` — Open-source license file.

---

## Pre-Commit Checklist

Before committing any change:

1. Run Pint for formatting: `./vendor/bin/pint`
2. Run tests: `composer test`
3. Run static analysis: `composer analyse`
4. Verify autoload & discovery: `composer dump-autoload`
5. Confirm PRD alignment for naming and functionality.
6. Ensure no temporary debugging or unused imports remain.
7. Verify that every class includes a valid doc block with purpose and PRD reference.

---

## Enforcement

Any contribution that violates these standards or PRD requirements will be rejected or revised before merge.

Every Agent is required to:

* Follow this guide precisely.
* Use PRDs as the **single source of truth** for all logic, naming, and intent.
* Include PHPDoc blocks at the top of every class describing purpose and PRD linkage.
* Seek clarification when a PRD is ambiguous or missing required details.

> **Failure to follow the PRD or this guide will result in revision or rejection of the contribution.**
