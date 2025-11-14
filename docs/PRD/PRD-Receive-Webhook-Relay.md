
# PRD — Receive Webhook Relay

## Overview

Receive Webhook Relay is the first stage of Atlas Relay. It guarantees that every inbound webhook request (or internal payload) is captured, normalized, validated, and stored before any business logic executes. Guard classes authenticate requests up front, the payload extractor normalizes JSON bodies, and the resulting relay record becomes the system of record for downstream delivery.

## Relay Schema (`atlas_relays`)

| Field                  | Description                                                                 |
|------------------------|-----------------------------------------------------------------------------|
| `id`                   | Relay identifier.                                                           |
| `type`                 | `RelayType` enum. Inbound captures store `INBOUND`; other flows may use `OUTBOUND` or `RELAY`. |
| `status`               | `RelayStatus` enum (Queued, Processing, Completed, Failed, Cancelled).      |
| `provider`             | Optional integration/provider label (indexed).                              |
| `reference_id`         | Optional consumer-provided reference (indexed).                             |
| `source_ip`            | Inbound IPv4 address detected from the HTTP request.                        |
| `headers`              | Normalized header JSON with sensitive keys masked.                          |
| `payload`              | Stored JSON payload (truncated when the capture limit is exceeded).         |
| `method`               | HTTP verb detected from the request (if present).                           |
| `url`                  | Full inbound URL (or target URL for outbound calls).                        |
| `failure_reason`       | `RelayFailure` enum for capture or downstream failures.                     |
| `meta`                 | Consumer-defined JSON metadata captured alongside the relay.                |
| `response_http_status` | Last recorded outbound HTTP status.                                         |
| `response_payload`     | Truncated outbound response payload.                                        |
| `processing_at`        | Timestamp for when processing began.                                        |
| `completed_at`         | Timestamp for when the relay finished (success/failure/cancel).             |
| `created_at`           | Capture timestamp.                                                          |
| `updated_at`           | Last state change.                                                          |

## RelayType enum (`Enums\RelayType`)

| Value | Label    | Usage                                                            |
|-------|----------|------------------------------------------------------------------|
| 1     | INBOUND  | Automatically applied when `Relay::request()` captures a webhook.|
| 2     | OUTBOUND | Applied when issuing webhooks directly via `Relay::http()` without a request context. |
| 3     | RELAY    | Default classification for internal/system-driven relays.        |

## Failure Reason Enum (`Enums\RelayFailure`)

| Code | Label                 | Description                                               |
|------|-----------------------|-----------------------------------------------------------|
| 100  | EXCEPTION             | Uncaught exception.                                       |
| 101  | PAYLOAD_TOO_LARGE     | Payload exceeds 64KB.                                     |
| 102  | NO_ROUTE_MATCH        | Legacy code (reserved).                                   |
| 103  | CANCELLED             | Manually cancelled.                                       |
| 104  | ROUTE_TIMEOUT         | Processing timeout.                                       |
| 105  | INVALID_PAYLOAD       | JSON decode failure.                                      |
| 108  | INVALID_GUARD_HEADERS | Guard rejected the request before processing because headers failed validation. |
| 109  | INVALID_GUARD_PAYLOAD | Guard rejected payload contents before processing.        |
| 201  | HTTP_ERROR            | Non‑2xx response.                                         |
| 205  | CONNECTION_ERROR      | Network/SSL/DNS failure.                                  |
| 206  | CONNECTION_TIMEOUT    | HTTP timeout.                                             |

## Inbound Guard Classes

Inbound guards are authored as plain PHP classes and registered inline via `guard(StripeWebhookGuard::class)`. No configuration files are required. Guards can **implement** `Atlas\Relay\Contracts\InboundRequestGuardInterface` or extend the convenience base class `Atlas\Relay\Guards\BaseInboundRequestGuard`.

- `validateHeaders()` is responsible for authentication or signature validation and should throw `Atlas\Relay\Exceptions\InvalidWebhookHeadersException` when the check fails. Atlas automatically surfaces `RelayFailure::INVALID_GUARD_HEADERS` (code `108`) for captured attempts.
- `validatePayload()` receives the normalized payload array/object and should throw `Atlas\Relay\Exceptions\InvalidWebhookPayloadException` for schema problems. Atlas records `RelayFailure::INVALID_GUARD_PAYLOAD` (code `109`) with the violations.
- Both validation methods receive an `InboundRequestGuardContext` that already contains the `Request`, normalized headers, decoded payload, and (when configured) the persisted `Relay` model. Consumers never need to rehydrate these values manually.
- `captureFailures()` controls whether a failing guard persists the relay before throwing. Return `true` for audit trails or `false` for providers that should not log rejected attempts.
- Guard methods are optional—leave either method untouched if only headers or payload validation is required.

### Example guard class
```php
use Atlas\Relay\Exceptions\InvalidWebhookHeadersException;
use Atlas\Relay\Exceptions\InvalidWebhookPayloadException;
use Atlas\Relay\Guards\BaseInboundRequestGuard;
use Atlas\Relay\Support\InboundRequestGuardContext;
use Illuminate\Support\Arr;

class StripeWebhookGuard extends BaseInboundRequestGuard
{
    public function validateHeaders(InboundRequestGuardContext $context): void
    {
        $signature = $context->header('Stripe-Signature');

        if ($signature === null) {
            throw InvalidWebhookHeadersException::fromViolations($this->name(), ['missing Stripe-Signature header']);
        }

        $expected = hash_hmac('sha256', $context->request()->getContent(), config('services.stripe.webhook_secret'));

        if (! hash_equals($expected, $signature)) {
            throw InvalidWebhookHeadersException::fromViolations($this->name(), ['signature mismatch']);
        }
    }

    public function validatePayload(InboundRequestGuardContext $context): void
    {
        $payload = Arr::wrap($context->payload());
        $type = Arr::get($payload, 'type');

        if (! in_array($type, ['charge.succeeded', 'charge.failed'], true)) {
            throw InvalidWebhookPayloadException::fromViolations($this->name(), ['unsupported webhook type']);
        }
    }

    public function captureFailures(): bool
    {
        return true; // audit blocked attempts
    }
}
```

### Guard exception handling
```php
use Atlas\Relay\Exceptions\InvalidWebhookHeadersException;
use Atlas\Relay\Exceptions\InvalidWebhookPayloadException;
use Illuminate\Http\Request;

public function __invoke(Request $request)
{
    try {
        Relay::request($request)
            ->provider('stripe')
            ->guard(\App\Guards\StripeWebhookGuard::class)
            ->event(fn ($payload) => $this->handleEvent($payload));

        return response()->json(['message' => 'ok']);
    } catch (InvalidWebhookHeadersException $exception) {
        return response()->json(['message' => 'Forbidden'], 403);
    } catch (InvalidWebhookPayloadException $exception) {
        return response()->json(['message' => $exception->getMessage()], 422);
    }
}
```

## Capture Rules

- Payloads are truncated when `atlas-relay.payload_max_bytes` is exceeded and the relay is marked `PAYLOAD_TOO_LARGE`.
- Sensitive headers are masked according to `atlas-relay.sensitive_headers`.
- Destination URLs longer than 255 characters are rejected with `InvalidDestinationUrlException`.
- When guards opt-in via `captureFailures()`, relays are stored even when validation fails, ensuring auditability.
