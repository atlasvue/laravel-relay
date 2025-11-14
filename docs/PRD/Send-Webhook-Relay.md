# PRD — Send Webhook Relay

Atlas Relay defines how outbound webhooks are constructed, configured, executed, and captured using Laravel’s HTTP client through `Relay::http()`.

## Table of Contents
- [High-Level Flow](#high-level-flow)
- [Using Relay::http()](#using-relayhttp)
- [HTTP Execution Behavior](#http-execution-behavior)
- [Failure Handling](#failure-handling)
- [Examples](#examples)
- [Lifecycle Rules](#lifecycle-rules)
- [Usage Link](#usage-link)

## High-Level Flow
Relay::http() → Configure → Send → Record Response → Complete/Fail

1. Initialize outbound relay
2. Apply headers and HTTP options
3. Execute HTTP verb (post/get/etc.)
4. Record request + response lifecycle fields
5. Mark Completed or Failed

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
- Supported HTTP options: `timeout()`, `retry()`, `acceptJson()`, and more

## Failure Handling

| Scenario                | Failure Code        |
|-------------------------|---------------------|
| Non‑2xx response        | HTTP_ERROR          |
| Network/DNS/SSL failure | CONNECTION_ERROR    |
| HTTP timeout            | CONNECTION_TIMEOUT  |
| Payload too large       | PAYLOAD_TOO_LARGE   |

## Examples

### Simple Outbound Send
```php
Relay::http()->post('https://api.partner.com/events', [
    'type' => 'user.updated',
]);
```

### Outbound With Retries
```php
Relay::http()
    ->retry(3, 500)
    ->post('https://hooks.example.com/ingest', $payload);
```

### Outbound With Additional HTTP Options
```php
Relay::http()
    ->timeout(10)
    ->acceptJson()
    ->withHeaders(['X-App' => 'Atlas'])
    ->post('https://hooks.example.com/run', $payload);
```

## Lifecycle Rules
- Starts in **Queued**
- Moves to **Processing** when HTTP begins
- **Completed** on success
- **Failed** on exception or error codes
- `completed_at` always set

## Usage Link
See **[Example Usage](./Example-Usage.md)** for full outbound usage examples.

## Also See
- [Atlas Relay](./Atlas-Relay.md)
- [Receive Webhook Relay](./Receive-Webhook-Relay.md)
- [Archiving & Logging](./Archiving-and-Logging.md)
- [Example Usage](./Example-Usage.md)
