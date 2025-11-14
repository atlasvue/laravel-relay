# PRD — Send Webhook Relay

## Purpose
This PRD defines **how Atlas Relay sends outbound webhooks** using Laravel’s `Http` client through `Relay::http()`.  
It focuses exclusively on **sending** webhooks — not receiving, guarding, or capturing inbound requests.

For inbound flow, see:  
**[Receive Webhook Relay](./Receive-Webhook-Relay.md)**

For usage examples, see:  
**[Example Usage](./Example-Usage.md#4-direct-http)**

Full API reference:  
**[Full API](../Full-API.md)**

---

## High‑Level Flow
**Relay::http() → Configure → Send → Record Response → Complete/Fail**

1. Initialize outbound relay (`OUTBOUND`)
2. Configure headers/options using Laravel’s `Http` methods
3. Execute HTTP verb (`post()`, `get()`, etc.)
4. Atlas records:
    - URL
    - Method
    - Headers (masked as needed)
    - Request payload
    - Response status
    - Response payload (truncated)
5. Relay is marked **Completed** or **Failed**

---

## Outbound Model
Outbound relays:

- Always use `RelayType::OUTBOUND`
- Are captured automatically when the HTTP request is executed
- Store **full request + response lifecycle metadata**

Lifecycle fields come from the `atlas_relays` schema (see Full API doc).

---

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

### With Provider + Reference ID (Analytics)

```php
Relay::provider('stripe')
    ->setReferenceId('ord-123')
    ->http()
    ->post('https://api.example.com/webhook', $payload);
```

---

## HTTP Execution Behavior

- **Laravel’s HTTP client** performs the real request
- Relay tracks the entire lifecycle by wrapping the request
- Headers from:
    - `withHeaders()`
    - `provider()` defaults
    - inbound snapshot (if applicable)
      merge into the relay record
- All HTTP options work normally:
    - `timeout()`
    - `retry()`
    - `acceptJson()`
    - etc.

Example:

```php
Relay::http()
    ->timeout(10)
    ->retry(3, 200)
    ->post('https://api.example.com/receive', $payload);
```

---

## Failure Handling

Failures automatically map to Relay Failure codes:

| Scenario                | RelayFailure       |
|-------------------------|--------------------|
| Non‑2xx response        | HTTP_ERROR         |
| Network/DNS/SSL failure | CONNECTION_ERROR   |
| HTTP timeout            | CONNECTION_TIMEOUT |
| Payload too large       | PAYLOAD_TOO_LARGE  |

Relay lifecycle is updated accordingly.

---

## Lifecycle Rules

- Relay starts in **Queued**
- When HTTP execution begins → **Processing**
- On valid response → **Completed**
- On exception/error → **Failed**
- `completed_at` is always updated

More lifecycle rules:  
**[Full API](../Full-API.md#delivery--lifecycle-services)**

---

## Notes

- Relay does **not** implement retries — consumers may trigger retries manually
- HTTPS/redirect behavior is completely controlled by Laravel’s HTTP client
- Payload/response data are truncated according to package config
- No guard or validation exists in outbound flows

---

This PRD defines only the **outbound send** behavior.  
For practical examples, see:  
**[Example Usage](./Example-Usage.md#4-direct-http)**
