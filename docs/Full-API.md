# Atlas Relay Public API

This document enumerates every public surface Atlas Relay exposes to consuming Laravel applications. Use it alongside the PRDs in `docs/PRD`—the PRDs remain the source of truth for behaviour, naming, and sequencing.

---

## Container Bindings & Facade Entrypoints

| Accessor | Resolves To | Notes |
| --- | --- | --- |
| `Atlas\Relay\Providers\AtlasRelayServiceProvider` | Auto-discovered package provider | Registers config, migrations, commands, and singletons. |
| `Atlas\Relay\Facades\Relay` facade | `atlas-relay.manager` binding | Fluent API entrypoint; import via `use Atlas\Relay\Facades\Relay;`. |
| `Atlas\Relay\Contracts\RelayManagerInterface` / `app('atlas-relay.manager')` | `Atlas\Relay\RelayManager` | Provides `request()`, `payload()`, `cancel()`, `replay()` builder entrypoints. |
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
| `Relay::request(Request $request): RelayBuilder` | Seed a builder from an inbound HTTP request; headers and method feed routing heuristics. |
| `Relay::payload(mixed $payload): RelayBuilder` | Seed a builder without an HTTP request (e.g. internal events). |
| `Relay::cancel(Relay $relay): Relay` | Set the relay status to `cancelled` (uses lifecycle service). |
| `Relay::replay(Relay $relay): Relay` | Reset lifecycle timestamps/attempt counts and enqueue the relay again. |

### Builder Configuration

| Method | Purpose |
| --- | --- |
| `request(Request $request)` | Replace/define the inbound request snapshot. |
| `payload(mixed $payload)` | Provide raw payload data (array/stdClass/scalar). |
| `mode(string $mode)` | Force a specific delivery mode label stored on the relay. |
| `retry(?int $seconds = null, ?int $maxAttempts = null)` | Enable retry semantics and optionally override cadence / max attempts. |
| `disableRetry()` | Explicitly disable retries even if routes suggest otherwise. |
| `delay(?int $seconds)` | Mark relay as delayed and set delay window. |
| `timeout(?int $seconds)` / `httpTimeout(?int $seconds)` | Override lifecycle or HTTP timeout thresholds. |
| `maxAttempts(?int $maxAttempts)` | Override the `max_attempts` cap. |
| `meta(array $meta)` / `mergeMeta(array $meta)` | Replace or merge extra metadata stored with the relay (e.g. tags). |
| `validationError(string $field, string $message)` | Append validation feedback that is persisted alongside the relay. |
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
| `dispatchEvent(callable $callback): PendingDispatch` | Persists then dispatches `DispatchRelayEventJob` to run the callback asynchronously. |
| `dispatchAutoRoute(): self` | Uses the Router to resolve an outbound destination, persists the relay, and queues delivery. |
| `autoRouteImmediately(): self` | Same as above but returns immediately after synchronous HTTP execution. |
| `http(): RelayHttpClient` | Returns a HTTP proxy constrained by PRD HTTPS/redirect rules; call verbs like `->post($url, $payload)`. |
| `dispatch(mixed $job): PendingDispatch` | Dispatches any Laravel job while injecting relay middleware for lifecycle tracking. |
| `dispatchSync(mixed $job): mixed` | Runs the job synchronously with lifecycle guards and propagates `RelayJobFailedException`. |
| `dispatchChain(array $jobs): PendingChain` | Builds a chain/chain-of-chains while ensuring each job carries relay middleware. |

---

## Delivery & Lifecycle Services

### RelayDeliveryService

| Method | Use Case |
| --- | --- |
| `executeEvent(Relay $relay, callable $callback)` | Wrap synchronous callbacks with lifecycle start/finish bookkeeping. |
| `dispatchEventAsync(Relay $relay, callable $callback)` | Queue a closure via `DispatchRelayEventJob`. |
| `http(Relay $relay): RelayHttpClient` | Create a pending HTTP proxy bound to a relay. |
| `dispatch(Relay $relay, mixed $job)` | Dispatch queued jobs after injecting `RelayJobMiddleware`. |
| `dispatchSync(Relay $relay, mixed $job)` | Dispatch jobs synchronously with `RelayJobContext` support. |
| `runQueuedEventCallback(callable $callback)` | Invoked by queue workers to execute stored callbacks. |
| `dispatchChain(Relay $relay, array $jobs)` | Produces a `RelayPendingChain` with middleware applied to every branch. |

### RelayLifecycleService

| Method | Behaviour |
| --- | --- |
| `startAttempt(Relay $relay)` | Increment attempt counters, mark status `RelayStatus::PROCESSING`, dispatch `RelayAttemptStarted`. |
| `markCompleted(Relay $relay, array $attributes = [], ?int $durationMs = null)` | Persist completion metadata, set status `RelayStatus::COMPLETED`, and fire `RelayCompleted`. |
| `markFailed(Relay $relay, RelayFailure $failure, array $attributes = [], ?int $durationMs = null)` | Persist failure data, set status `RelayStatus::FAILED`, and fire `RelayFailed`. |
| `recordResponse(Relay $relay, ?int $status, mixed $payload)` | Store outbound response metadata, applying truncation rules per delivery channel. |
| `recordExceptionResponse(Relay $relay, Throwable $exception)` | Persist a shortened exception summary when event or job callbacks crash unexpectedly. |
| `cancel(Relay $relay, ?RelayFailure $reason = null)` | Set status `RelayStatus::CANCELLED` and clear retry metadata. |
| `replay(Relay $relay)` | Reset lifecycle columns and set status `RelayStatus::QUEUED`, allowing automation to pick the relay back up. |

### RelayCaptureService

| Method | Description |
| --- | --- |
| `capture(RelayContext $context): Relay` | Applies payload size limits, masks headers, merges lifecycle defaults, and persists to the `atlas_relays` table while logging/dispatching `RelayCaptured`. |

---

## HTTP, Jobs, and Scheduler Helpers

| Component | Key Methods | Notes |
| --- | --- | --- |
| `RelayHttpClient` | Dynamic HTTP verbs (`get`, `post`, etc.) plus pass-through to `PendingRequest` configurators (e.g. `withHeaders`, `timeout`) | Enforces HTTPS (unless disabled), redirect host pinning, payload truncation, and lifecycle updates. |
| `RelayScheduler::register(Schedule $schedule)` | Registers retry, stuck, timeout, archive, and purge commands with cron expressions sourced from `atlas-relay.automation.*`. |
| `RelayJobMiddleware` | `handle(object $job, Closure $next)` | Add to custom jobs to automatically start/stop lifecycle attempts. |
| `RelayJobContext` | `set()`, `current()`, `clear()` | Static helper used by middleware to expose the active relay to downstream code. |
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
| `Atlas\Relay\Models\Relay` | Live relay records capturing lifecycle + payload metadata. | `scopeDueForRetry()` (ready retries), `scopeUnarchived()`. Table name configurable via `atlas-relay.tables.relays`. |
| `Atlas\Relay\Models\RelayRoute` | Persisted routing definitions used by AutoRoute modes. | `scopeEnabled()`. Table configurable via `atlas-relay.tables.relay_routes`. |
| `Atlas\Relay\Models\RelayArchive` | Long-term storage for completed/failed relays. | `scopeEligibleForPurge(int $retentionDays)`. Table configurable via `atlas-relay.tables.relay_archives`. |

All models inherit from `AtlasModel`, which reads the target table names from config at construction time.

> **Status Casting:** The `status` attribute on relay and archive models uses the `Enums\RelayStatus` PHP enum and is stored as an unsigned tinyint (`0`–`4`).

---

## Events

| Event | Fired When |
| --- | --- |
| `RelayCaptured` | Immediately after a relay is persisted. |
| `RelayAttemptStarted` | When lifecycle transitions a relay into `processing`. |
| `RelayCompleted` | After an attempt succeeds; includes optional duration. |
| `RelayFailed` | After an attempt fails; carries the `RelayFailure` code. |
| `RelayRequeued` | When automation re-enqueues a stuck/overdue relay. |
| `RelayRestored` | When a relay is restored from the archive table. |
| `AutomationMetrics` | After automation commands finish; exposes `operation`, `count`, `durationMs`, and contextual metadata. |

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
| `Atlas\Relay\Jobs\DispatchRelayEventJob` | Queued wrapper that calls `RelayDeliveryService::runQueuedEventCallback()` with the serialized closure provided by `RelayBuilder::dispatchEvent()`. Middleware automatically maintains lifecycle state. |

---

## Testing & Support Structures

| Component | Usage |
| --- | --- |
| `Atlas\Relay\Support\RelayContext` | Immutable value object passed into `RelayCaptureService::capture()`; useful when asserting builder state in tests. |
| `Atlas\Relay\Support\RelayJobContext` | Static per-job relay store; call `RelayJobContext::current()` from anywhere in the job stack. |

Use these helpers when extending Atlas Relay, writing package tests, or integrating with your own automation around the relay lifecycle.
