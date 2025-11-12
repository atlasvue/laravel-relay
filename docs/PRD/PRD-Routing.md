# PRD — Auto Routing

## Overview

The **Routing** module defines how Atlas Relay determines the correct delivery destination or handler for a given relay. It provides the logic for **AutoRouting**, which dynamically maps inbound or internally triggered relays to their respective outbound endpoints or execution targets.

Routing is the bridge between payload capture and outbound delivery. It ensures that every relay knows *where* to go and *how* to get there—whether that means performing an HTTP delivery, dispatching an event, or executing a job handler.

---

## Goals

* Provide automatic destination resolution based on path, tags, or rules.
* Support multiple routing types: **Static**, **Dynamic**, and **Programmatic**.
* Maintain predictable and cacheable routing behavior for low-latency matching.
* Simplify developer configuration—no manual domain registration required.
* Allow custom providers or local rule sets for route definition.

---

## Routing Flow Summary

**Inbound Payload / Relay Request** → Route Lookup → Route Match Found → Outbound or Handler Determined → Delivery Execution

---

## Functional Description

### 1. Route Resolution

Routing determines a destination based on the relay’s metadata, payload, or configured rules. The router operates in one of three modes:

| Mode             | Description                                                                                                        |
|------------------|--------------------------------------------------------------------------------------------------------------------|
| **Static**       | Matches fixed path and method pairs (e.g., `POST /orders/create`).                                                 |
| **Dynamic**      | Supports parameterized paths (e.g., `/event/{TYPE}`) and type-constrained segments (e.g., `{CUSTOMER_ID:int}`).    |
| **Programmatic** | Executes a custom resolver (callback, class, or provider) to determine route dynamically based on payload content. |

---

### 2. Route Definitions

Routes are defined in the `atlas_relay_routes` table or through programmatic registration at runtime.

| Field             | Description                                                                                   |
|-------------------|-----------------------------------------------------------------------------------------------|
| `id`              | Unique route ID.                                                                              |
| `identifier`      | Optional label for reference (e.g., `lead.created`).                                          |
| `method`          | HTTP method to match (e.g., POST, GET).                                                       |
| `path`            | Request path pattern (supports dynamic segments).                                             |
| `type`            | Route type (`http`, `event`, `dispatch`).                                                     |
| `destination_url` | URL or handler reference (e.g., `https://api.example.com/webhook`, `App\Events\LeadCreated`). |
| `headers`         | JSON object of custom headers (applied if HTTP type).                                         |
| `retry_policy`    | JSON configuration for retries and delays.                                                    |
| `timeout_seconds` | Max duration allowed for outbound execution.                                                  |
| `enabled`         | Boolean flag indicating if route is active.                                                   |
| `created_at`      | Timestamp of creation.                                                                        |
| `updated_at`      | Timestamp of last modification.                                                               |

---

### 3. Matching Logic

When an inbound request or relay is received:

1. Match is attempted by `method + path`.
2. If no strict match, attempt dynamic path match (substituting `{PARAM}` tokens).
3. If still unresolved, check for a registered programmatic resolver.
4. If no match is found, relay is marked `Failed` with `failure_reason = NO_ROUTE_MATCH`.

Dynamic parameters can capture segments and inject them into the outbound URL or handler context.

#### Example

| Inbound Path | Route Pattern     | Match | Captured Parameters |
|--------------|-------------------|-------|---------------------|
| `/lead/123`  | `/lead/{LEAD_ID}` | ✅     | `{LEAD_ID: 123}`    |

---

### 4. AutoRouting Behavior

When a relay calls `.dispatchAutoRoute()` or `.autoRouteImmediately()`, the system performs automatic route resolution and determines the delivery target.

#### AutoRouting Decision Tree

**Relay Configuration Initialization**

* When a route match occurs, the router **copies** route delivery defaults into the new relay record:
    * `is_retry`, `retry_seconds`, `retry_max_attempts`
    * `is_delay`, `delay_seconds`
    * `timeout_seconds`, `http_timeout_seconds`
* API-specified values on relay creation take precedence over route defaults.
* Subsequent edits to the route do **not** affect existing relays.

1. Find a route that matches the inbound method and path.
2. Apply cached lookup if available (20-minute TTL).
3. If matched:

    * Load route configuration and headers.
    * Resolve delivery type (`http`, `event`, or `dispatch`).
    * Forward to Outbound Delivery subsystem.
4. If no match:

    * Mark relay as `Failed` with `NO_ROUTE_MATCH`.

---

### 5. Programmatic Providers

Developers may register **routing providers** using a standardized interface:

```php
Routing::registerProvider('leads', new LeadRouteProvider());
```

Providers implement `determine(RouteContext $context): ?RouteResult` and return a matched route dynamically.

This enables integration with external systems or configuration-driven routing logic (e.g., tenant-based routing, payload-specific rules).

---

### 6. Cache Behavior

* Routes are cached by `(method, path)` key for 20 minutes.
* Cache invalidates automatically when routes are added, updated, or deleted.
* Programmatic providers are not cached unless they declare `cacheable = true`.

---

### 7. Route Resolution Priority

1. Programmatic providers (explicitly registered)
2. Dynamic route patterns (with parameter matching)
3. Static route patterns (exact path match)

This ensures developer-defined logic always takes precedence over static configuration.

---

## Failure Handling

| Condition          | Action                                             |
|--------------------|----------------------------------------------------|
| No route matched   | Relay marked `Failed` with `NO_ROUTE_MATCH`.       |
| Route disabled     | Relay marked `Failed` with `ROUTE_DISABLED`.       |
| Resolver exception | Relay marked `Failed` with `ROUTE_RESOLVER_ERROR`. |

---

## Observability

All lifecycle and activity details are recorded directly in the `atlas_relays` table. Each relay entry captures inbound and outbound events, status transitions, retry counts, durations, responses, and failure reasons inline.
## Lifecycle Flow Summary

**Captured → Queued → Processing → (Completed | Failed | Cancelled) → Archived**

Each relay passes through a defined sequence of states that represent its progression from intake to resolution.

---

## Dependencies & Integration

* **Depends on:** [PRD — Payload Capture](./PRD-Payload-Capture.md), [PRD — Routing](./PRD-Routing.md)
* **Integrates with:** [PRD — Atlas Relay](./PRD-Atlas-Relay.md) lifecycle and job automation

---

## Notes

* Domains and manual domain registration have been removed. All routes are global or provider-based.
* AutoRouting is now the default delivery path for inbound relays.
* The cache layer must never override active route states.
* Matching and dispatch must be deterministic across retries.

---

## Outstanding Questions / Clarifications

* Should programmatic providers be able to return multiple possible destinations (fan-out)?
* Should route resolution log payload attributes used for matching?
* Should cached lookups persist across deployments or reset on startup?

---

### See Also
* [PRD — Atlas Relay](./PRD-Atlas-Relay.md)
* [PRD — Payload Capture](./PRD-Payload-Capture.md)
* [PRD — Routing](./PRD-Routing.md)
* [PRD — Outbound Delivery](./PRD-Outbound-Delivery.md)
* [PRD — Archiving](./PRD-Archiving-and-Logging.md)
* [PRD — Example Usage](./PRD-Example-Usage.md)
