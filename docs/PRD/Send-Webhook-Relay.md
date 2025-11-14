# PRD — Send Webhook Relay

Atlas Relay defines how outbound webhooks are constructed, configured, executed, and captured using Laravel’s HTTP client through `Relay::http()`.

## Table of Contents
- [High-Level Flow](#high-level-flow)
- [Outbound Model](#outbound-model)
- [Using Relay::http()](#using-relayhttp)
- [HTTP Execution Behavior](#http-execution-behavior)
- [Failure Handling](#failure-handling)
- [Lifecycle Rules](#lifecycle-rules)
- [Notes](#notes)

## High-Level Flow
Relay::http() → Configure → Send → Record Response → Complete/Fail

1. Initialize outbound relay
2. Apply headers and HTTP options
3. Execute HTTP verb (post/get/etc.)
4. Record request + response lifecycle fields
5. Mark Completed or Failed

## Outbound Model
Outbound relays:

- Always use `OUTBOUND`
- Are captured automatically upon HTTP execution
- Store full request + response details

All lifecycle fields come from the Atlas Relay schema.

## Using Relay::http()
### Basic Example
```php
Relay::http()->post('https://api.example.com/webhook', [
    'event' => 'order.created',
]);
```

### With Headers
```php
Relay::http()
    ->withHeaders(['X-Auth' => '123'])
    ->post('https://api.example.com/webhook', $payload);
```

### With Provider + Reference ID
```php
Relay::provider('stripe')
    ->setReferenceId('ord-123')
    ->http()
    ->post('https://api.example.com/webhook', $payload);
```

## HTTP Execution Behavior
- Laravel’s HTTP client performs the actual request
- Relay captures:
    - method
    - URL
    - headers
    - payload
    - response status
    - response body (truncated)
- HTTP options such as `timeout()`, `retry()`, `acceptJson()` are fully supported

Full outbound delivery logic is covered in **Example Usage**.

## Failure Handling
Mapped failure codes:

| Scenario                | Failure Code       |
|-------------------------|--------------------|
| Non‑2xx response        | HTTP_ERROR         |
| Network/DNS/SSL failure | CONNECTION_ERROR   |
| HTTP timeout            | CONNECTION_TIMEOUT |
| Payload too large       | PAYLOAD_TOO_LARGE  |

## Lifecycle Rules
- Starts in **Queued**
- Moves to **Processing** when HTTP begins
- **Completed** on success
- **Failed** on exception or failing status codes
- `completed_at` always set

## Notes
- No automatic retry system
- Redirects/SSL behavior handled by Laravel HTTP client
- Truncation for payload & response controlled by package config

## Also See
- [Atlas Relay](./Atlas-Relay.md)
- [Receive Webhook Relay](./Receive-Webhook-Relay.md)
- [Archiving & Logging](./Archiving-and-Logging.md)
- [Example Usage](./Example-Usage.md)
