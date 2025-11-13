# PRD — Atlas Relay Example Usage

## Overview

This document shows **exact, practical usage** of Atlas Relay with a focus on **dispatching Laravel jobs through Atlas** while preserving **full access to Laravel’s native queue controls**. Atlas wraps dispatch to **track relay lifecycle** (success/failure, failure_reason, timings) but **does not change** Laravel semantics or APIs.

See also (technical foundations): `PRD-Payload-Capture.md`, `PRD-Routing.md`, `PRD-Outbound-Delivery.md`, `PRD-Archiving-and-Logging.md`.

---

## Goals

- Show how to **dispatch Laravel jobs via Atlas** and still use all Laravel controls (`onQueue`, `onConnection`, `delay`, `afterCommit`, `$tries`, `backoff()`, `middleware()`, `Bus::chain`, etc.).
- Demonstrate that Atlas **returns Laravel’s native types** (e.g., `PendingDispatch`) so chaining works exactly as if you called Laravel directly.
- Make clear that Atlas **records lifecycle** automatically before control returns to the caller.

---

## 1) Dispatch a Job via Atlas (and chain Laravel controls)

```php
use Atlas\Relay\Facades\Relay;

$pending = Relay::payload($payload)
    ->dispatch(new ExampleJob($payload));   // <-- thin wrapper over Laravel dispatch

// $pending is Laravel\Foundation\Bus\PendingDispatch (same as ExampleJob::dispatch())
$pending->onQueue('critical')
        ->onConnection('redis')
        ->delay(now()->addMinutes(5))
        ->afterCommit();
```

**What you get**
- **Exact Laravel behavior** on `$pending` (it’s the native `PendingDispatch`).
- Atlas intercepts job completion/failure to set the originating relay’s status (`Completed`/`Failed`) and `failure_reason` when applicable.

---

## 2) Synchronous Dispatch via Atlas

```php
Relay::payload($payload)
    ->dispatchSync(new ExampleJob($payload));   // thin wrapper over dispatchSync()
```

**Behavior**
- Runs in the same request cycle.
- Atlas marks the relay **Completed** on success or **Failed** with mapped `failure_reason` on exception.

---

## 3) Dispatch with Job Settings ($tries, backoff, middleware)

```php
// ExampleJob.php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ExampleJob implements ShouldQueue
{
    use Queueable;

    public $tries = 5;                 // or use retryUntil()
    public function backoff() { return [60, 300]; }  // progressive (seconds)
    public function middleware() { return [ new RateLimited('partner-api') ]; }

    public function handle() {
        // job logic
    }
}

// Call site (returns native PendingDispatch):
Relay::payload($payload)->dispatch(new ExampleJob($payload))->onQueue('default');
```

**Behavior**
- All job-level Laravel features work normally; Atlas only **records lifecycle**.

---

## 4) Chaining Jobs via Atlas (Bus::chain)

```php
use Illuminate\Support\Facades\Bus;

Relay::payload($payload)
    ->dispatchChain([                 // thin wrapper that proxies to Bus::chain(...)->dispatch()
        new PrepareDataJob($payload),
        new DeliverToPartnerJob(),
        new NotifyDownstreamJob(),
    ])->onConnection('redis')->onQueue('pipeline');
```

**Behavior**
- Returns Laravel’s native chain handle (so you can set `connection`/`queue`).
- Atlas observes the chain completion/failure and updates the relay accordingly.

---

## 5) Combine AutoRoute + Dispatch

```php
// If your route resolves to a dispatch-type destination, Atlas copies route defaults
// (retry/delay/timeout) to the relay at capture. Your job code stays 100% Laravel.

Relay::request($request)
    ->dispatchAutoRoute();   // router selects a dispatch destination per PRD-Routing
```

**Behavior**
- Route defaults (`is_retry`, `retry_seconds`, `retry_max_attempts`, `is_delay`, `delay_seconds`, `timeout_seconds`, `http_timeout_seconds`) copied onto the relay at creation.
- `Relay::request()` already captured the inbound payload, so dispatch routes receive the webhook body without an extra `payload()` call.
- Your job is still dispatched using **Laravel native** dispatch — Atlas wraps to **record lifecycle**.

---

## 6) Direct HTTP (for reference)

```php
Relay::payload($data)
    ->http()
    ->withHeaders(['X-App' => 'Atlas'])
    ->timeout(30)        // Laravel HTTP client timeout (seconds)
    ->retry(3, 2000)     // Laravel transport-level retry (ms delay)
    ->post('https://api.partner.com/receive', $data);
```

**Behavior**
- Delegates to Laravel’s `Http` under the hood; **all client features** available.
- Atlas intercepts first, records `response_http_status`/`response_payload`, then returns the response.

---

## Notes

- Atlas **dispatch/dispatchSync/dispatchChain** are **thin wrappers** that return the **same Laravel types** you expect and preserve **all Laravel controls**.
- Atlas **never** forces new base classes or job signatures.
- Lifecycle and error mapping happen **automatically** (and are written to the relay record and logs).
