
# PRD — Receive Webhook Relay

## Overview

Receive Webhook Relay is the first stage of Atlas Relay. It guarantees that every inbound webhook request (or internal payload) is captured, normalized, validated, and stored before any business logic executes. Guard profiles authenticate requests up front, the payload extractor normalizes JSON bodies, and the resulting relay record becomes the system of record for downstream delivery.

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
| 108  | FORBIDDEN_GUARD       | Provider guard rejected the request before processing.    |
| 201  | HTTP_ERROR            | Non‑2xx response.                                         |
| 203  | TOO_MANY_REDIRECTS    | Redirect limit exceeded.                                  |
| 204  | REDIRECT_HOST_CHANGED | Redirect host mismatch.                                   |
| 205  | CONNECTION_ERROR      | Network/SSL/DNS failure.                                  |
| 206  | CONNECTION_TIMEOUT    | HTTP timeout.                                             |

## Provider Guards

Provider-level guard profiles enforce authentication requirements before any webhook proceeds. Configure them in `config/atlas-relay.php`:

```php
'inbound' => [
    'provider_guards' => [
        'stripe' => 'stripe-signature',
    ],
    'guards' => [
        'stripe-signature' => [
            'capture_forbidden' => true,
            'required_headers' => [
                'Stripe-Signature',
                'X-Relay-Key' => env('RELAY_SHARED_KEY'),
            ],
            'validator' => \App\Guards\StripeWebhookValidator::class, // optional
        ],
    ],
];
```

Guards can be mapped via `provider('stripe')` or specified explicitly with `guard('stripe-signature')`. When the guard rejects a request, Atlas throws `Atlas\Relay\Exceptions\ForbiddenWebhookException` (auth failure) or `Atlas\Relay\Exceptions\InvalidWebhookPayloadException` (payload validation failure) and marks the relay with `RelayFailure::FORBIDDEN_GUARD` or `RelayFailure::INVALID_PAYLOAD` when `capture_forbidden` is `true`. Set `capture_forbidden` to `false` for test/local providers to skip persisting failed attempts while still enforcing the guard.

### Guard exception handling
```php
use Atlas\Relay\Exceptions\ForbiddenWebhookException;
use Atlas\Relay\Exceptions\InvalidWebhookPayloadException;
use Illuminate\Http\Request;

public function __invoke(Request $request)
{
    try {
        Relay::request($request)
            ->provider('stripe')
            ->event(fn ($payload) => $this->handleEvent($payload));

        return response()->json(['message' => 'ok']);
    } catch (ForbiddenWebhookException $exception) {
        return response()->json(['message' => 'Forbidden'], 403);
    } catch (InvalidWebhookPayloadException $exception) {
        return response()->json(['message' => $exception->getMessage()], 422);
    }
}
```

### Validator example
```php
use Atlas\Relay\Contracts\InboundGuardValidatorInterface;
use Atlas\Relay\Exceptions\ForbiddenWebhookException;
use Atlas\Relay\Exceptions\InvalidWebhookPayloadException;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Support\InboundGuardProfile;
use Illuminate\Http\Request;

class StripeWebhookValidator implements InboundGuardValidatorInterface
{
    /**
     * @param  list<string>  $requiredKeys
     */
    public function __construct(
        private readonly array $requiredKeys = ['id', 'type', 'data.object'],
    ) {}

    public function validate(Request $request, InboundGuardProfile $profile, ?Relay $relay = null): void
    {
        $payload = $request->json()->all();

        if (! is_array($payload)) {
            throw InvalidWebhookPayloadException::fromViolations($profile->name, ['payload must be JSON']);
        }

        foreach ($this->requiredKeys as $path) {
            if (! data_get($payload, $path)) {
                throw InvalidWebhookPayloadException::fromViolations($profile->name, [
                    sprintf('missing required payload key [%s]', $path),
                ]);
            }
        }

        if (! in_array(data_get($payload, 'type'), ['charge.succeeded', 'charge.failed'], true)) {
            throw ForbiddenWebhookException::fromViolations($profile->name, ['unsupported event type']);
        }
    }
}
```

## Capture Rules

- Payloads are truncated when `atlas-relay.payload.max_bytes` is exceeded and the relay is marked `PAYLOAD_TOO_LARGE`.
- Sensitive headers are masked according to `atlas-relay.capture.sensitive_headers`.
- Destination URLs longer than 255 characters are rejected with `InvalidDestinationUrlException`.
- When guards opt-in to `capture_forbidden`, relays are stored even when authentication fails, ensuring auditability.
