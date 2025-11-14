# Atlas Relay Public API

This document enumerates every public surface Atlas Relay exposes to consuming Laravel applications. Use it alongside the PRDs in `docs/PRD`—the PRDs remain the source of truth for behaviour, naming, and sequencing.

---

## Container Bindings & Facade Entrypoints

| Accessor | Resolves To | Notes |
| --- | --- | --- |
| `Atlas\Relay\Providers\AtlasRelayServiceProvider` | Auto-discovered package provider | Registers config, migrations, commands, and singletons. |
| `Atlas\Relay\Facades\Relay` facade | `atlas-relay.manager` binding | Fluent API entrypoint; import via `use Atlas\Relay\Facades\Relay;`. |
| `Atlas\Relay\Contracts\RelayManagerInterface` / `app('atlas-relay.manager')` | `Atlas\Relay\RelayManager` | Provides `request()`, `payload()`, `type()`, `http()`, `cancel()` entrypoints. |
| `Atlas\Relay\Services\RelayCaptureService` | Capture service singleton | Persistent relay storage according to the Receive Webhook Relay PRD. |
| `Atlas\Relay\Services\RelayLifecycleService` | Lifecycle service singleton | Cancelling and marking relays; used by delivery helpers. |
| `Atlas\Relay\Services\RelayDeliveryService` | Delivery orchestrator singleton | HTTP/event/job execution wrappers. |
| `Atlas\Relay\Support\RelayJobHelper` | Helper singleton | Injectable into jobs for accessing relay context and raising failures. |

---

## Relay Manager & Fluent Builder

### Manager Methods

| Method | Description |
| --- | --- |
| `Relay::request(Request $request): RelayBuilder` | Seed a builder from an inbound HTTP request; headers, method, and payload are copied automatically for delivery callbacks. |
| `Relay::payload(mixed $payload): RelayBuilder` | Seed a builder when no HTTP request exists (internal/system triggers). Prefer `request()`/`http()` for typical flows. |
| `Relay::type(RelayType $type): RelayBuilder` | Override the inferred relay type (e.g., force `RelayType::OUTBOUND`). |
| `Relay::provider(?string $provider): RelayBuilder` | Start a builder, tag it with the provider identifier, and continue configuring (works great before calling `http()`). |
| `Relay::setReferenceId(?string $referenceId): RelayBuilder` | Same as above but for consumer-defined reference IDs, enabling tagging before issuing `http()` calls. |
| `Relay::guard(?string $guard): RelayBuilder` | Attach an inbound guard class (`class-string<InboundRequestGuardInterface>`) that validates the request before capture. |
| `Relay::http(): RelayHttpClient` | Return a ready-to-use HTTP client that captures payload + destination directly from the Laravel HTTP call. |
| `Relay::cancel(Relay $relay): Relay` | Set the relay status to `cancelled` (uses lifecycle service). |

### Builder Configuration

| Method | Purpose |
| --- | --- |
| `request(Request $request)` | Replace/define the inbound request snapshot (also extracts payload when present). |
| `payload(mixed $payload)` | Provide raw payload data (array/stdClass/scalar) or override what was extracted from the request. |
| `meta(mixed $meta)` | Attach arbitrary JSON metadata that persists with the relay for downstream analytics. |
| `type(RelayType $type)` | Explicitly set the `RelayType` (defaults to `INBOUND` for `request()` and `OUTBOUND` for `http()`). |
| `validationError(string $field, string $message)` | Append validation feedback for reporting/logging prior to capture. |
| `failWith(RelayFailure $failure, RelayStatus $status = RelayStatus::FAILED)` | Prefill capture state to failed with a specific failure code. |
| `status(RelayStatus $status)` | Override the initial status before capture. |
| `provider(?string $provider)` | Associate the relay with a provider slug for downstream analytics/filters. Accepts `null` to clear. |
| `setReferenceId(?string $referenceId)` | Store a consumer-defined identifier (order ID, case ID, etc.) alongside the relay record. |
| `guard(?string $guard)` | Provide a guard class that will receive the inbound headers/payload before capture. |

> **RelayStatus enum:** Status-related methods accept values from `Enums\RelayStatus`, which is stored as an unsigned tinyint on relay records.

### Persistence & Inspection

| Method | Result |
| --- | --- |
| `capture(): Relay` | Forces persistence via `RelayCaptureService` and returns the model. |
| `relay(): ?Relay` | Returns the last persisted relay instance without re-capturing. |
| `context(): RelayContext` | Exposes the immutable capture payload (useful for tests). |

> **Inbound Guards:** Call `guard(YourGuard::class)` to run inline guard classes that implement `InboundRequestGuardInterface`. Atlas injects headers, payloads, and the request context automatically so guards can throw `InvalidWebhookHeadersException` or `InvalidWebhookPayloadException` without additional plumbing. Returning `true` from `captureFailures()` records the rejected relay for auditing.

### Delivery Actions

| Method | Description |
| --- | --- |
| `event(callable $callback): mixed` | Captures the relay and executes the callback synchronously; the callback receives `(payload, Relay)` when it accepts parameters. |
| `http(): RelayHttpClient` | Returns a HTTP proxy that records relay lifecycle data while leaving HTTP client options under consumer control (call verbs like `->post($url, $payload)`). |
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
| `markCompleted(Relay $relay, array $attributes = [], ?int $durationMs = null)` | Persist completion metadata, set status `RelayStatus::COMPLETED`, and clear `failure_reason`. |
| `markFailed(Relay $relay, RelayFailure $failure, array $attributes = [], ?int $durationMs = null)` | Persist failure data, set status `RelayStatus::FAILED`, and store the failure reason. |
| `recordResponse(Relay $relay, ?int $status, mixed $payload)` | Store outbound response details (`response_http_status` + payload) with truncation rules. |
| `recordExceptionResponse(Relay $relay, Throwable $exception)` | Persist a shortened exception summary when event or job callbacks crash unexpectedly. |
| `cancel(Relay $relay, ?RelayFailure $reason = null)` | Set status `RelayStatus::CANCELLED` and clear scheduling timestamps. |

### RelayCaptureService

| Method | Description |
| --- | --- |
| `capture(RelayContext $context): Relay` | Applies payload size limits, masks headers, and persists to the `atlas_relays` table. |

---

## HTTP, Jobs, and Scheduler Helpers

| Component | Key Methods | Notes |
| --- | --- | --- |
| `RelayHttpClient` | Dynamic HTTP verbs (`get`, `post`, etc.) plus pass-through to `PendingRequest` configurators (e.g. `withHeaders`, `timeout`) | Captures headers/method/url, truncates payloads at the configured limit, and records responses/failures without overriding your HTTP options. |
| `RelayJobMiddleware` | `handle(object $job, Closure $next)` | Add to custom jobs to automatically start/stop lifecycle attempts. |
| `RelayJobContext` | `set()`, `current()`, `clear()` | Scoped helper resolved via the container to expose the active relay to downstream code. |
| `RelayJobHelper` | `relay()`, `fail(RelayFailure $failure, string $message = '', array $attributes = [])` | Resolve via container inside jobs for convenience APIs. |
| `RelayPendingChain` | Overrides `dispatch()`, `dispatchIf()`, `dispatchUnless()` | Ensures the head job in a chain also receives `RelayJobMiddleware`. |

---

## Models

| Model | Purpose | Helpers |
| --- | --- | --- |
| `Atlas\Relay\Models\Relay` | Live relay records capturing lifecycle + payload state. | `scopeDueForRetry()` (ready retries). Table name configurable via `atlas-relay.tables.relays`. |
| `Atlas\Relay\Models\RelayArchive` | Long-term storage for completed/failed relays. | `scopeEligibleForPurge(int $retentionDays)`. Table configurable via `atlas-relay.tables.relay_archives`. |

All models inherit from `AtlasModel`, which reads the target table names from config at construction time.

> **Status Casting:** The `status` attribute on relay and archive models uses the `Enums\RelayStatus` PHP enum and is stored as an unsigned tinyint (`0`–`4`).

---

## Console Commands

| Command | Description |
| --- | --- |
| `atlas-relay:archive {--chunk=}` | Moves completed/failed relays into the archive table (`--chunk` controls the batch size; defaults to `500`). |
| `atlas-relay:purge-archives` | Deletes archived relays older than `atlas-relay.archiving.purge_after_days`. |
| `atlas-relay:relay:restore {id}` | Restores an archived relay into the live table. |
| `atlas-relay:relay:inspect {id}` | Prints the JSON state for a live or archived relay. |

Register the recurring commands inside `routes/console.php` using Laravel’s scheduling helpers:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('atlas-relay:archive')->dailyAt('22:00');
Schedule::command('atlas-relay:purge-archives')->dailyAt('23:00');
```

Adjust the cadence as needed for your environment or run the commands manually.

---

## Configuration Reference (`config/atlas-relay.php`)

| Key | Description / Env Override |
| --- | --- |
| `tables.relays`, `tables.relay_archives` | Customize table names. |
| `payload_max_bytes` | Unified max byte size for captured payloads, stored responses, and exception summaries (default 64KB). |
| `sensitive_headers` | Header block list automatically masked to `*********` across inbound and outbound snapshots. |
| `archiving.archive_after_days`, `archiving.purge_after_days` | Retention windows for archival and purge jobs. Use `atlas-relay:archive --chunk=` to adjust batch size (default `500`). |

---

## Enums & Exceptions

| Component | Details |
| --- | --- |
| `Atlas\Relay\Enums\RelayType` | Distinguishes relay intent (`INBOUND`, `OUTBOUND`, `RELAY`). Builders infer the type automatically but you can call `type()` to override it. |
| `Atlas\Relay\Enums\RelayFailure` | Canonical failure codes (`PAYLOAD_TOO_LARGE`, `NO_ROUTE_MATCH`, etc.) with helper `label()`/`description()`. Use them when forcing failures or handling lifecycle callbacks. |
| `Atlas\Relay\Exceptions\RelayHttpException` | Thrown when HTTP delivery is misconfigured (missing URL, unsupported method, payload/URL size violations). Call `failure()` to obtain the associated `RelayFailure`. |
| `Atlas\Relay\Exceptions\InvalidWebhookHeadersException` | Raised when guard header validation fails; consumers should return `403` (or similar) and consult relay logs for `INVALID_GUARD_HEADERS` failures. |
| `Atlas\Relay\Exceptions\InvalidWebhookPayloadException` | Raised when guard payload validation fails; consumers should return `422` (or similar) and inspect relay logs for `INVALID_GUARD_PAYLOAD` failures. |
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
