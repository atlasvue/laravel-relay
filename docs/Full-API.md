# Atlas Relay Public API

This document enumerates every public surface Atlas Relay exposes to consuming Laravel applications. Use it alongside the PRDs in `docs/PRD`—the PRDs remain the source of truth for behaviour, naming, and sequencing.

---

## Container Bindings & Facade Entrypoints

| Accessor | Resolves To | Notes |
| --- | --- | --- |
| `Atlas\Relay\Providers\AtlasRelayServiceProvider` | Auto-discovered package provider | Registers config, migrations, commands, and singletons. |
| `Atlas\Relay\Facades\Relay` facade | `atlas-relay.manager` binding | Fluent API entrypoint; import via `use Atlas\Relay\Facades\Relay;`. |
| `Atlas\Relay\Contracts\RelayManagerInterface` / `app('atlas-relay.manager')` | `Atlas\Relay\RelayManager` | Provides `request()`, `payload()`, `http()`, `cancel()`, `replay()` entrypoints. |
| `Atlas\Relay\Routing\Router` / `app('atlas-relay.router')` | Router singleton | Supports database routes and custom providers. |
| `Atlas\Relay\Services\RelayCaptureService` | Capture service singleton | Persistent relay storage according to Payload Capture PRD. |
| `Atlas\Relay\Services\RelayLifecycleService` | Lifecycle service singleton | Cancelling/replaying/marking relays; used by delivery helpers. |
| `Atlas\Relay\Services\RelayDeliveryService` | Delivery orchestrator singleton | HTTP/event/job execution wrappers. |
| `Atlas\Relay\Support\RelayJobHelper` | Helper singleton | Injectable into jobs for accessing relay context and raising failures. |

---

## Relay Manager & Fluent Builder

### Manager Methods

| Method | Description |
| --- | --- |
| `Relay::request(Request $request): RelayBuilder` | Seed a builder from an inbound HTTP request; headers, method, and payload are copied automatically for routing heuristics and delivery callbacks. |
| `Relay::payload(mixed $payload): RelayBuilder` | Seed a builder when no HTTP request exists (internal/system triggers). Prefer `request()`/`http()` for typical flows. |
| `Relay::http(): RelayHttpClient` | Return a ready-to-use HTTP client that captures payload + destination directly from the Laravel HTTP call. |
| `Relay::cancel(Relay $relay): Relay` | Set the relay status to `cancelled` (uses lifecycle service). |
| `Relay::replay(Relay $relay): Relay` | Reset lifecycle timestamps/attempt counts and enqueue the relay again. |

### Builder Configuration

| Method | Purpose |
| --- | --- |
| `request(Request $request)` | Replace/define the inbound request snapshot (also extracts payload when present). |
| `payload(mixed $payload)` | Provide raw payload data (array/stdClass/scalar) or override what was extracted from the request. |
| `mode(string $mode)` | Force a specific delivery mode label stored on the relay. |
| `retry(?int $seconds = null, ?int $maxAttempts = null)` | Enable retry semantics and optionally override cadence / max attempts. |
| `disableRetry()` | Explicitly disable retries even if routes suggest otherwise. |
| `delay(?int $seconds)` | Mark relay as delayed and set delay window. |
| `timeout(?int $seconds)` / `httpTimeout(?int $seconds)` | Override lifecycle or HTTP timeout thresholds. |
| `maxAttempts(?int $maxAttempts)` | Convenience helper for overriding `retry_max_attempts`. |
| `validationError(string $field, string $message)` | Append validation feedback for reporting/logging prior to capture. |
| `failWith(RelayFailure $failure, RelayStatus $status = RelayStatus::FAILED)` | Prefill capture state to failed with a specific failure code. |
| `status(RelayStatus $status)` | Override the initial status before capture. |

> **RelayStatus enum:** Status-related methods accept values from `Enums\RelayStatus`, which is stored as an unsigned tinyint on relay records.

### Persistence & Inspection

| Method | Result |
| --- | --- |
| `capture(): Relay` | Forces persistence via `RelayCaptureService` and returns the model. |
| `relay(): ?Relay` | Returns the last persisted relay instance without re-capturing. |
| `context(): RelayContext` | Exposes the immutable capture payload (useful for tests). |

### Delivery Actions

| Method | Description |
| --- | --- |
| `event(callable $callback): mixed` | Captures the relay and executes the callback synchronously; the callback receives `(payload, Relay)` when it accepts parameters. |
| `dispatchAutoRoute(): self` | Uses the Router to resolve an outbound destination, persists the relay, and queues delivery. |
| `autoRouteImmediately(): self` | Same as above but returns immediately after synchronous HTTP execution. |
| `http(): RelayHttpClient` | Returns a HTTP proxy constrained by PRD HTTPS/redirect rules; call verbs like `->post($url, $payload)`. |
| `dispatch(mixed $job): PendingDispatch` | Dispatches any Laravel job while injecting relay middleware for lifecycle tracking. |
| `dispatchChain(array $jobs): PendingChain` | Builds a chain/chain-of-chains while ensuring each job carries relay middleware. |

---

## Delivery & Lifecycle Services

### RelayDeliveryService

| Method | Use Case |
| --- | --- |
| `executeEvent(Relay $relay, callable $callback)` | Wrap synchronous callbacks with lifecycle start/finish bookkeeping. |
| `http(Relay $relay, array $headers = []): RelayHttpClient` | Create a pending HTTP proxy bound to a relay and seed it with merged headers. |
| `dispatch(Relay $relay, mixed $job)` | Dispatch queued jobs after injecting `RelayJobMiddleware`. |
| `runQueuedEventCallback(callable $callback)` | Invoked by queue workers to execute stored callbacks. |
| `dispatchChain(Relay $relay, array $jobs)` | Produces a `RelayPendingChain` with middleware applied to every branch. |

HTTP deliveries merge headers in this order: inbound request snapshot (when using `Relay::request()`), headers you configure directly on the returned Laravel HTTP client (via `withHeaders()`, `accept()`, etc.), and finally any route-defined headers. Later layers win when duplicate names exist, ensuring consumer-specific overrides always take precedence.

### RelayLifecycleService

| Method | Behaviour |
| --- | --- |
| `startAttempt(Relay $relay)` | Increment attempt counters, mark status `RelayStatus::PROCESSING`, and stamp processing timestamps. |
| `markCompleted(Relay $relay, array $attributes = [], ?int $durationMs = null)` | Persist completion metadata, set status `RelayStatus::COMPLETED`, and reset retry metadata. |
| `markFailed(Relay $relay, RelayFailure $failure, array $attributes = [], ?int $durationMs = null)` | Persist failure data, set status `RelayStatus::FAILED`, and store the failure reason. |
| `recordResponse(Relay $relay, ?int $status, mixed $payload)` | Store outbound response details (`response_http_status` + payload) with truncation rules. |
| `recordExceptionResponse(Relay $relay, Throwable $exception)` | Persist a shortened exception summary when event or job callbacks crash unexpectedly. |
| `cancel(Relay $relay, ?RelayFailure $reason = null)` | Set status `RelayStatus::CANCELLED` and clear retry metadata. |
| `replay(Relay $relay)` | Reset lifecycle columns and set status `RelayStatus::QUEUED`, allowing automation to pick the relay back up. |

### RelayCaptureService

| Method | Description |
| --- | --- |
| `capture(RelayContext $context): Relay` | Applies payload size limits, masks headers, merges lifecycle defaults, and persists to the `atlas_relays` table. |

---

## HTTP, Jobs, and Scheduler Helpers

| Component | Key Methods | Notes |
| --- | --- | --- |
| `RelayHttpClient` | Dynamic HTTP verbs (`get`, `post`, etc.) plus pass-through to `PendingRequest` configurators (e.g. `withHeaders`, `timeout`) | Enforces HTTPS (unless disabled), redirect host pinning, payload truncation, and lifecycle updates. |
| `RelayScheduler::register(Schedule $schedule)` | Registers retry, stuck, timeout, archive, and purge commands with cron expressions sourced from `atlas-relay.automation.*`. |
| `RelayJobMiddleware` | `handle(object $job, Closure $next)` | Add to custom jobs to automatically start/stop lifecycle attempts. |
| `RelayJobContext` | `set()`, `current()`, `clear()` | Scoped helper resolved via the container to expose the active relay to downstream code. |
| `RelayJobHelper` | `relay()`, `fail(RelayFailure $failure, string $message = '', array $attributes = [])` | Resolve via container inside jobs for convenience APIs. |
| `RelayPendingChain` | Overrides `dispatch()`, `dispatchIf()`, `dispatchUnless()` | Ensures the head job in a chain also receives `RelayJobMiddleware`. |

---

## Routing API

| Element | Public Surface | Usage |
| --- | --- | --- |
| `Router` | `registerProvider(string $name, RoutingProviderInterface $provider)`, `flushCache()`, `resolve(RouteContext $context): RouteResult` | Register programmatic providers (e.g. in a service provider) or rely on database routes. `resolve()` throws `RoutingException` when no route or resolver errors occur. |
| `RoutingProviderInterface` | `determine(RouteContext $context): ?RouteResult`, `cacheKey(RouteContext $context): ?string`, `cacheTtlSeconds(): ?int` | Implement to provide dynamic routing; returning `null` from `cacheKey()` disables caching while non-null keys cache the result (optionally overriding TTL via `cacheTtlSeconds()`). |
| `RouteContext` | `fromRequest(?Request $request, mixed $payload = null)`, `normalizedMethod()`, `normalizedPath()` | Build contexts from inbound request + payload for manual routing or provider testing. |
| `RouteResult` | `fromModel(RelayRoute $route, array $parameters = [])`, `fromArray(array $data)`, `toArray()` | Value object describing resolved routes, including headers/lifecycle defaults/parameters. |
| `RoutingException` | `noRoute()`, `disabledRoute()`, `resolverError()` | Exception type the router throws; inspect `$exception->failure` for `RelayFailure` codes. |

---

## Models

| Model | Purpose | Helpers |
| --- | --- | --- |
| `Atlas\Relay\Models\Relay` | Live relay records capturing lifecycle + payload state. | `scopeDueForRetry()` (ready retries). Table name configurable via `atlas-relay.tables.relays`. |
| `Atlas\Relay\Models\RelayRoute` | Persisted routing definitions used by AutoRoute modes. | `scopeEnabled()`. Table configurable via `atlas-relay.tables.relay_routes`. |
| `Atlas\Relay\Models\RelayArchive` | Long-term storage for completed/failed relays. | `scopeEligibleForPurge(int $retentionDays)`. Table configurable via `atlas-relay.tables.relay_archives`. |

All models inherit from `AtlasModel`, which reads the target table names from config at construction time.

> **Status Casting:** The `status` attribute on relay and archive models uses the `Enums\RelayStatus` PHP enum and is stored as an unsigned tinyint (`0`–`4`).

---

## Console Commands

| Command | Description |
| --- | --- |
| `atlas-relay:retry-overdue` | Requeues relays whose retry window elapsed. |
| `atlas-relay:requeue-stuck` | Moves relays stuck in `processing` back to `queued`. |
| `atlas-relay:enforce-timeouts` | Marks relays as failed when they exceed timeout thresholds. |
| `atlas-relay:archive {--chunk=500}` | Moves completed/failed relays into the archive table. |
| `atlas-relay:purge-archives` | Deletes archived relays older than `atlas-relay.archiving.purge_after_days`. |
| `atlas-relay:relay:restore {id}` | Restores an archived relay into the live table. |
| `atlas-relay:relay:inspect {id}` | Prints the JSON state for a live or archived relay. |
| `atlas-relay:routes:seed path.json` | Bulk seeds relay routes from a JSON definition file. |

Tie these commands into Laravel’s scheduler via `RelayScheduler::register($schedule)` or run them manually.

---

## Configuration Reference (`config/atlas-relay.php`)

| Key | Description / Env Override |
| --- | --- |
| `tables.relays`, `tables.relay_routes`, `tables.relay_archives` | Customize table names. |
| `capture.max_payload_bytes` (`ATLAS_RELAY_MAX_PAYLOAD_BYTES`) | Max captured payload size (default 64KB). |
| `capture.sensitive_headers`, `capture.header_whitelist`, `capture.masked_value` | Header masking allow/deny lists. |
| `lifecycle.default_retry_seconds`, `default_retry_max_attempts`, `default_delay_seconds`, `default_timeout_seconds`, `default_http_timeout_seconds` | Global delivery defaults. |
| `lifecycle.exception_response_max_bytes` (`ATLAS_RELAY_EXCEPTION_RESPONSE_MAX_BYTES`) | Max bytes stored for exception summaries recorded in `response_payload`. |
| `routing.cache_ttl_seconds`, `routing.cache_store` | Router cache behaviour. |
| `http.max_response_bytes`, `http.max_redirects`, `http.enforce_https` | Outbound HTTP safeties. |
| `archiving.archive_after_days`, `archiving.purge_after_days`, `archiving.chunk_size` | Archiving cadence and chunk sizing. |
| `automation.*` (`retry_overdue_cron`, `stuck_requeue_cron`, `timeout_enforcement_cron`, `archive_cron`, `purge_cron`, `stuck_threshold_minutes`, `timeout_buffer_seconds`) | Scheduler expressions + safety buffers. |

---

## Enums & Exceptions

| Component | Details |
| --- | --- |
| `Atlas\Relay\Enums\RelayFailure` | Canonical failure codes (`PAYLOAD_TOO_LARGE`, `NO_ROUTE_MATCH`, etc.) with helper `label()`/`description()`. Use them when forcing failures or handling lifecycle callbacks. |
| `Atlas\Relay\Exceptions\RelayHttpException` | Thrown for HTTPS enforcement, redirect violations, or other outbound HTTP guard rails. Call `failure()` to obtain the associated `RelayFailure`. |
| `Atlas\Relay\Exceptions\RelayJobFailedException` | Throw (or use `RelayJobHelper::fail()`) inside jobs to mark a relay as failed with custom attributes. |

---

## Jobs

| Job | Purpose |
| --- | --- |

---

## Testing & Support Structures

| Component | Usage |
| --- | --- |
| `Atlas\Relay\Support\RelayContext` | Immutable value object passed into `RelayCaptureService::capture()`; useful when asserting builder state in tests. |
| `Atlas\Relay\Support\RelayJobContext` | Scoped per-job relay store resolved via the container; call `app(RelayJobContext::class)->current()` or use `RelayJobHelper`. |

Use these helpers when extending Atlas Relay, writing package tests, or integrating with your own automation around the relay lifecycle.
