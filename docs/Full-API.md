# Atlas Relay — Public API Reference
This document lists **all public APIs** exposed by Atlas Relay.  
It aims to be **exhaustive, minimal, and strictly focused on method availability**, not examples.

For lifecycle rules, schema, and functional behavior, see:
- **[PRD — Atlas Relay](./PRD/PRD-Atlas-Relay.md)**
- **[PRD — Receive Webhook Relay](./PRD/PRD-Receive-Webhook-Relay.md)**
- **[PRD — Send Webhook Relay](./PRD/PRD-Send-Webhook-Relay.md)**

---

# 1. Facade Entrypoint

### `Atlas\Relay\Facades\Relay`
Central access point for creating and managing relays.

---

# 2. Manager-Level Public API
Resolved via the Relay facade.

| Method             | Signature                                   | Purpose                                           |
|--------------------|---------------------------------------------|---------------------------------------------------|
| `request()`        | `request(Request $request): RelayBuilder`   | Begin an **inbound** relay from an HTTP request.  |
| `payload()`        | `payload(mixed $payload): RelayBuilder`     | Begin a relay from raw payload (system/internal). |
| `type()`           | `type(RelayType $type): RelayBuilder`       | Override inferred relay type.                     |
| `provider()`       | `provider(?string $provider): RelayBuilder` | Tag the relay with an integration key.            |
| `setReferenceId()` | `setReferenceId(?string $id): RelayBuilder` | Attach a reference ID (order ID, etc.).           |
| `guard()`          | `guard(?string $guardClass): RelayBuilder`  | Apply an inbound guard class.                     |
| `http()`           | `http(): RelayHttpClient`                   | Begin an **outbound** HTTP relay.                 |
| `cancel()`         | `cancel(Relay $relay): Relay`               | Mark relay as cancelled.                          |

---

# 3. RelayBuilder API
Returned from `Relay::request()`, `Relay::payload()`, etc.

## 3.1 Configuration Methods

| Method              | Signature                                                       | Purpose                                     |
|---------------------|-----------------------------------------------------------------|---------------------------------------------|
| `payload()`         | `payload(mixed $payload)`                                       | Override or define relay payload.           |
| `meta()`            | `meta(mixed $meta)`                                             | Store custom metadata array/JSON.           |
| `type()`            | `type(RelayType $type)`                                         | Set relay type explicitly.                  |
| `provider()`        | `provider(?string)`                                             | Override provider.                          |
| `setReferenceId()`  | `setReferenceId(?string)`                                       | Override reference ID.                      |
| `guard()`           | `guard(?string)`                                                | Attach inbound guard.                       |
| `status()`          | `status(RelayStatus $status)`                                   | Set initial status (rare).                  |
| `validationError()` | `validationError(string $field, string $message)`               | Store validation error info before capture. |
| `failWith()`        | `failWith(RelayFailure $failure, RelayStatus $status = FAILED)` | Force capture to start in failed state.     |

## 3.2 Capture + Inspection

| Method      | Signature                 | Purpose                         |
|-------------|---------------------------|---------------------------------|
| `capture()` | `capture(): Relay`        | Persist relay immediately.      |
| `relay()`   | `relay(): ?Relay`         | Get last persisted relay.       |
| `context()` | `context(): RelayContext` | Get immutable capture snapshot. |

---

# 4. Delivery API (Event / Jobs / HTTP)

## 4.1 Event Execution

| Method    | Signature                          | Purpose                                            |
|-----------|------------------------------------|----------------------------------------------------|
| `event()` | `event(callable $callback): mixed` | Perform synchronous execution and track lifecycle. |

## 4.2 Job Dispatching

| Method            | Signature                                  | Purpose                                                 |
|-------------------|--------------------------------------------|---------------------------------------------------------|
| `dispatch()`      | `dispatch(mixed $job): PendingDispatch`    | Dispatch a job with automatic relay lifecycle handling. |
| `dispatchChain()` | `dispatchChain(array $jobs): PendingChain` | Dispatch a job chain with lifecycle propagation.        |

## 4.3 HTTP Execution (Outbound)

Available through **RelayHttpClient**, returned by `Relay::http()`.

### HTTP Verbs
All Laravel HTTP verbs pass through:

- `get(string $url)`
- `post(string $url, array|string|null $payload)`
- `put(string $url, array|string|null $payload)`
- `patch(string $url, array|string|null $payload)`
- `delete(string $url, array|string|null $payload)`

### Request Configuration (Laravel-native)
The following methods are transparently proxied:

- `withHeaders(array $headers)`
- `timeout(int $seconds)`
- `retry(int $times, int $sleepMs)`
- `accept(string)`
- `asJson()`
- `attach(...)`
- All other `PendingRequest` configurators

---

# 5. Lifecycle & Delivery Services (Public)

While resolved internally, these services are publicly accessible for extension.

| Service                 | Public Methods                                                                                                   | Purpose                                         |
|-------------------------|------------------------------------------------------------------------------------------------------------------|-------------------------------------------------|
| `RelayDeliveryService`  | `executeEvent()`, `http()`, `dispatch()`, `dispatchChain()`, `runQueuedEventCallback()`                          | Orchestrates execution & lifecycle transitions. |
| `RelayLifecycleService` | `startAttempt()`, `markCompleted()`, `markFailed()`, `recordResponse()`, `recordExceptionResponse()`, `cancel()` | Low-level lifecycle controls.                   |
| `RelayCaptureService`   | `capture()`                                                                                                      | Low-level persistence used by builder.          |

Full semantics:  
**[PRD — Atlas Relay](./PRD/PRD-Atlas-Relay.md)**

---

# 6. Models

| Model                             | Purpose                |
|-----------------------------------|------------------------|
| `Atlas\Relay\Models\Relay`        | Live relay records     |
| `Atlas\Relay\Models\RelayArchive` | Archived relay records |

Both conform to the unified schema defined in:  
**[Relay Data Model](./PRD/PRD-Atlas-Relay.md#2-relay-data-model-full-field-specification)**

---

# 7. Console Commands

| Command                          | Purpose                         |
|----------------------------------|---------------------------------|
| `atlas-relay:archive`            | Archive completed/failed relays |
| `atlas-relay:purge-archives`     | Purge expired archives          |
| `atlas-relay:relay:inspect {id}` | Inspect live or archived relay  |
| `atlas-relay:relay:restore {id}` | Restore archive → live          |

Details:  
**[Archiving & Logging](./PRD/PRD-Archiving-and-Logging.md)**

---

# 8. Enums & Exceptions

## 8.1 Enums

| Enum           | Values                                           | Purpose                 |
|----------------|--------------------------------------------------|-------------------------|
| `RelayType`    | INBOUND, OUTBOUND, RELAY                         | Defines relay intent    |
| `RelayStatus`  | QUEUED, PROCESSING, COMPLETED, FAILED, CANCELLED | Lifecycle states        |
| `RelayFailure` | All canonical failure codes                      | Unified error semantics |

Full failure list:  
**[Failure Codes](./PRD/PRD-Atlas-Relay.md#3-failure-reason-enum-complete-spec)**

## 8.2 Exceptions

- `InvalidWebhookHeadersException`
- `InvalidWebhookPayloadException`
- `RelayHttpException`
- `RelayJobFailedException`

Used according to flow-specific rules:
- Inbound → **Receive Webhook PRD**
- Outbound → **Send Webhook PRD**

---

This reference represents the **complete public API surface** of Atlas Relay.  
All behavioral logic is defined in the PRDs linked above.
