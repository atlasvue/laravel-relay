
# PRD — Payload Capture

## Overview

Payload Capture is the first stage of Atlas Relay. It records every inbound payload—HTTP, internal, or programmatic—storing headers, source IP, and JSON data with full lifecycle visibility. Guard profiles run before capture to reject unauthenticated webhooks while still logging the attempt when configured. All captures become relay records and move into routing and processing.

## Relay Schema (`atlas_relays`)

| Field                  | Description                                             |
|------------------------|---------------------------------------------------------|
| `id`                   | Relay ID.                                               |
| `source_ip`            | Inbound IPv4 address detected from the request.         |
| `provider`             | Optional integration/provider label (indexed).          |
| `reference_id`         | Optional consumer-provided reference (indexed).         |
| `headers`              | Normalized header JSON.                                 |
| `payload`              | Stored JSON payload.                                    |
| `status`               | Enum: Queued, Processing, Completed, Failed, Cancelled. |
| `mode`                 | event, dispatch, autoroute, direct.                     |
| `failure_reason`       | Enum for capture or downstream failure.                 |
| `response_http_status` | HTTP status of last outbound request.                   |
| `response_payload`     | Truncated last HTTP response body.                      |
| `attempts`             | Number of processing attempts executed.                 |
| `next_retry_at`        | Next retry timestamp.                                   |
| `method`               | HTTP verb captured for inbound/outbound delivery.       |
| `url`                  | Normalized route or destination URL applied everywhere. |
| `processing_at`        | When the current attempt began processing.              |
| `completed_at`         | When the relay finished (success, failure, or cancel).  |
| `created_at`           | Capture timestamp.                                      |
| `updated_at`           | Last state change.                                      |

## Failure Reason Enum (`Enums\RelayFailure`)

| Code | Label                 | Description                                               |
|------|-----------------------|-----------------------------------------------------------|
| 100  | EXCEPTION             | Uncaught exception.                                       |
| 101  | PAYLOAD_TOO_LARGE     | Payload exceeds 64KB.                                     |
| 102  | NO_ROUTE_MATCH        | No route match.                                           |
| 103  | CANCELLED             | Manually cancelled.                                       |
| 104  | ROUTE_TIMEOUT         | Routing timeout.                                          |
| 105  | INVALID_PAYLOAD       | JSON decode failure.                                      |
| 108  | FORBIDDEN_GUARD       | Provider guard rejected the request before processing.    |
| 201  | HTTP_ERROR            | Non‑2xx response.                                         |
| 203  | TOO_MANY_REDIRECTS    | Redirect limit exceeded.                                  |
| 204  | REDIRECT_HOST_CHANGED | Redirect host mismatch.                                   |
| 205  | CONNECTION_ERROR      | Network/SSL/DNS failure.                                  |
| 206  | CONNECTION_TIMEOUT    | HTTP timeout.                                             |

## Provider Guards

Provider-level guard profiles enforce authentication headers before any webhook proceeds. Configure them in `config/atlas-relay.php`:

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
            'validator' => \App\Guards\StripeWebhookGuard::class, // optional
        ],
    ],
];
```

Guards can be mapped via `provider('stripe')` or specified explicitly with `guard('stripe-signature')`. When the guard rejects a request, Atlas throws `Atlas\Relay\Exceptions\ForbiddenWebhookException` (auth failure) or `Atlas\Relay\Exceptions\InvalidWebhookPayloadException` (payload validation failure) and marks the relay with `RelayFailure::FORBIDDEN_GUARD` or `RelayFailure::INVALID_PAYLOAD` when `capture_forbidden` is `true`. Set `capture_forbidden` to `false` for test/local providers to skip persisting failed attempts while still enforcing the guard.

### Example with guard exception handling
```php
use Atlas\Relay\Exceptions\ForbiddenWebhookException;
use Atlas\Relay\Exceptions\InvalidWebhookPayloadException;
use Illuminate\Http\Request;

public function __invoke(Request $request)
{
    try {
        Relay::request($request)
            ->provider('stripe')
            ->event(fn($payload) => $this->handleEvent($payload));

        return response()->json(['status' => 'ok']);
    } catch (ForbiddenWebhookException $exception) {
        // Expected guard failure — respond with 403 and skip error reporting.
        return response()->json(['message' => 'Forbidden'], 403);
    } catch (InvalidWebhookPayloadException $exception) {
        return response()->json(['message' => 'Payload rejected'], 422);
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
