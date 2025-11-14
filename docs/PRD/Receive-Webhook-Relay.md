# PRD — Receive Webhook Relay

Atlas Relay defines the rules for receiving, validating, normalizing, and capturing inbound webhook requests before any delivery or processing occurs.

## Table of Contents
- [High-Level Flow](#high-level-flow)
- [Guarding](#guarding)
- [Capture Rules](#capture-rules)
- [Schema (Inbound Fields)](#schema-inbound-fields)
- [Failure Codes](#failure-codes)
- [Examples](#examples)
- [Lifecycle Rules](#lifecycle-rules)
- [Usage Link](#usage-link)

## High-Level Flow
HTTP Request → Guard (optional) → Normalize → Capture → Event/Dispatch

1. Receive request
2. Normalize headers, payload, method, URL, IP
3. Run guard validation
4. Capture relay

## Guarding

- Require headers
- Validate payloads with Laravel’s validator
- Fail with custom messages
- Choose whether failures should be captured or ignored

**Guard Interfaces:**
```
Atlas\Relay\Contracts\InboundRequestGuardInterface
```

**Guard Base Class:**
```
Atlas\Relay\Guards\BaseInboundRequestGuard
```

## Capture Rules
Atlas Relay must:

- Initialize relay as `INBOUND`
- Normalize headers and payload
- Mask sensitive headers (`sensitive_headers`)
- Truncate oversized payloads (`payload_max_bytes`)
- Store inbound failure codes where applicable
- Persist the relay **before** any downstream logic

## Schema (Inbound Fields)

The inbound capture uses a subset of the full relay schema.  
For the **complete field list**, see the authoritative **[Atlas Relay PRD](./Atlas-Relay.md#relay-data-model)**.

| Field                        | Description                            |
|------------------------------|----------------------------------------|
| `type`                       | Always `INBOUND`                       |
| `headers`                    | normalized & masked                    |
| `payload`                    | decoded or raw if JSON fails           |
| `failure_reason`             | set on guard/capture failure           |
| `method`, `url`, `source_ip` | extracted from request                 |
| `processing_at`              | downstream start                       |
| `completed_at`               | lifecycle completion                   |

## Failure Codes

Inbound failures reference the global unified failure enum.  
See **[Atlas Relay Failure Codes](./Atlas-Relay.md#failure-reason-enum)** for the full list.

Inbound-specific failures:

| Code | Meaning               |
|------|-----------------------|
| 105  | INVALID_PAYLOAD       |
| 108  | INVALID_GUARD_HEADERS |
| 109  | INVALID_GUARD_PAYLOAD |
| 101  | PAYLOAD_TOO_LARGE     |

## Examples

### Route Controller With Guard and HTTP Responses

```php
use Atlas\Relay\Facades\Relay;
use Illuminate\Http\Request;

class WebhookController
{
    public function __invoke(Request $request)
    {
        try {
            Relay::request($request)
                ->guard(StripeWebhookGuard::class)
                ->event(fn ($payload) => $this->handleEvent($payload));

            return response()->json(['ok' => true], 200);
        } catch (\Throwable $e) {
            // Map validation / guard failures back to the webhook sender
            return response()->json([
                'message' => $e->getMessage(),
            ], 403);
        }
    }
}
```

This pattern shows a realistic route controller that:
- Uses a guard for validation/authorization.
- Processes the webhook via an event handler.
- Returns **200 OK** on success.
- Returns **403 Forbidden** with the exception message for consumer-facing errors.

### Basic Inbound Handling
```php
use Atlas\Relay\Facades\Relay;

public function __invoke(Request $request)
{
    Relay::request($request)
        ->event(fn ($payload) => $this->handleEvent($payload));

    return response()->json(['ok' => true]);
}
```

### Inbound With Guard
```php
Relay::request($request)
    ->provider('stripe')
    ->guard(StripeWebhookGuard::class)
    ->event(fn ($payload) => $this->handleEvent($payload));
```

### Failing Guard Example
```php

use Atlas\Relay\Guards\BaseInboundRequestGuard;
use Atlas\Relay\Guards\InboundRequestGuardContext;

class ExampleGuard extends BaseInboundRequestGuard
{
    public function validate(InboundRequestGuardContext $context): void
    {
        if (! $context->header('X-Signature')) {
            $context->failHeaders(['Missing X-Signature header']);
        }
    }
}
```

## Lifecycle Rules

- Starts in **Queued** when the inbound request is captured.
- Moves to **Processing** when the event handler, job, or downstream logic begins.
- **Completed** when processing finishes successfully.
- **Failed** when a guard, payload validation, or downstream exception occurs.
- `completed_at` is always set—whether the relay succeeds or fails.

## Usage Link
See **[Example Usage](./Example-Usage.md)** for complete inbound handling examples.

## Also See
- [Atlas Relay](./Atlas-Relay.md)
- [Send Webhook Relay](./Send-Webhook-Relay.md)
- [Archiving & Logging](./Archiving-and-Logging.md)
- [Example Usage](./Example-Usage.md)
