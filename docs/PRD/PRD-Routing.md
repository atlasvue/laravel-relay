
# PRD — Auto Routing

## Overview
Routing determines the correct destination or handler for each relay. It powers AutoRouting by mapping inbound or programmatic relays to the proper HTTP endpoint, event, or job handler.

---

## Goals
- Automatic destination resolution via static, dynamic, or programmatic routes.
- Predictable, cacheable behavior with low-latency matching.
- Minimal configuration required; supports optional providers.
- Clear routing rules that bridge payload capture → outbound delivery.

---

## Routing Flow
Inbound Relay → Route Lookup → Match Found → Delivery Target Selected → Execute Delivery

---

## Route Resolution Modes

| Mode             | Description                                                            |
|------------------|------------------------------------------------------------------------|
| **Static**       | Exact method + path match.                                             |
| **Dynamic**      | Parameterized paths (e.g., `/event/{TYPE}`). Supports typed params.    |
| **Programmatic** | Custom resolver returns route dynamically based on payload or context. |

---

## Route Definitions (`atlas_relay_routes`)
| Field                       | Description                     |
|-----------------------------|---------------------------------|
| `id`                        | Route ID                        |
| `identifier`                | Optional label (`lead.created`) |
| `method`                    | HTTP method                     |
| `path`                      | Supports dynamic segments       |
| `type`                      | `http`, `event`, `dispatch`     |
| `url`                       | URL or handler reference        |
| `headers`                   | JSON headers for HTTP           |
| `retry_policy`              | JSON retry config               |
| `timeout_seconds`           | Max execution duration          |
| `enabled`                   | Boolean active flag             |
| `created_at` / `updated_at` | Audit timestamps                |

---

## Matching Logic
1. Try exact match (`method + path`).
2. If no match, try dynamic match (resolve `{PARAM}` placeholders).
3. If still no match, call registered programmatic providers.
4. If unresolved → relay marked `Failed (NO_ROUTE_MATCH)`.

Dynamic parameters may be injected into outbound destinations.

---

## AutoRouting Behavior
Triggered by `.dispatchAutoRoute()` or `.autoRouteImmediately()`.

### Steps
1. Find route (cached, 20‑minute TTL).
2. When matched:
    - Persist the relay with the resolved `route_id`, normalized URL, and headers.
    - Retry/delay/timeout fields remain on the route; automation resolves them as needed.
    - Manual overrides are no longer copied to the relay—route config is the single source of truth.
3. Forward matched route to Outbound Delivery subsystem.
4. If no match → relay fails with `NO_ROUTE_MATCH`.

---

## Programmatic Providers
Providers can be registered to perform dynamic resolution:

```php
Routing::registerProvider('leads', new LeadRouteProvider());
```

Provider methods:
- `determine(RouteContext $context): ?RouteResult`
- Optional caching:
    - `cacheKey()` returns a key to enable caching; `null` disables it.
    - `cacheTtlSeconds()` overrides TTL.

Programmatic providers allow tenant-specific logic, advanced rules, or integration with external systems.

---

## Cache Behavior
- Routes cached by `(method, path)` for 20 minutes.
- Cache auto‑invalidates on route create/update/delete.
- Provider caching controlled per-provider via `cacheKey()` and optional TTL.

---

## Resolution Priority
1. Programmatic providers
2. Dynamic route patterns
3. Static exact matches

---

## Failure Handling
| Condition          | Result                               |
|--------------------|--------------------------------------|
| No match           | Relay fails (`NO_ROUTE_MATCH`)       |
| Route disabled     | Relay fails (`ROUTE_DISABLED`)       |
| Provider exception | Relay fails (`ROUTE_RESOLVER_ERROR`) |

---

## Observability
All routing decisions and results are reflected via inline relay fields in `atlas_relays` (status, failure_reason, attempts, durations, response info). Retry/delay/timeout metadata is stored on the route itself and read via `route_id`.

---

## Lifecycle Summary
Captured → Queued → Processing → Completed/Failed/Cancelled → Archived

---

## Notes
- Manual domain registration removed; routing is global.
- AutoRouting is the default path for inbound relays.
- Cache must respect route enabled/disabled state.
- Matching must remain deterministic on retries; updated config should be picked up automatically because it is read from the route each time.

---

## Outstanding Questions
- Should providers support multiple matches for fan‑out?
- Should route resolution log matched payload attributes?
- Should cached entries persist across deployments?
