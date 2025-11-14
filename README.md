# Atlas Relay

**Atlas Relay** is a Laravel package that provides a **complete relay system** for managing **inbound and outbound webhooks**, events, and payloads.

## 💡 Overview

Webhook handling is notoriously fragile; plagued by missing retries, inconsistent logging, and scattered error handling; Atlas Relay eliminates these pain points with a durable, observable pipeline that guarantees delivery and traceability.

Atlas Relay ensures:

* Every webhook is **stored before delivery** — never lost or skipped.
* Both **incoming and outgoing** requests share a single unified process.
* Every transaction is **auditable, replayable, and reliable**.
* The API supports **custom internal relays** or **HTTP dispatches** beyond webhooks.

---

## ⚡ Core Concepts

`Request → Payload Capture → Routing → Outbound Delivery → Complete → Archive`

Each stage of the lifecycle is defined in its own PRD:
- [Payload Capture](./docs/PRD/PRD-Payload-Capture.md): receiving and validating data
- [Routing](./docs/PRD/PRD-Routing.md): determining the correct destination (if using auto-route)
- [Outbound Delivery](./docs/PRD/PRD-Outbound-Delivery.md): transmitting payloads and handling retries
- [Archiving & Logging](./docs/PRD/PRD-Archiving-and-Logging.md): long-term retention and audit trails

---

## 🧰 Installation

### Composer

```bash
composer require atlas-php/relay
```

Additional setup instructions (config publish, migrations, scheduler, etc.) live in [`docs/Install.md`](./docs/Install.md).

---

## 🧩 Example Usage

### Receive a Webhook

Using `Relay::request($request)` automatically grabs the inbound payload (JSON) and sends it into your events for processing. See [Payload Capture](./docs/PRD/PRD-Payload-Capture.md) for more details.

```php
// The most common way
Relay::request($request)->event(fn($payload) => $this->handleEvent($payload));

// OR you can dispatch for async processing
Relay::request($request)->dispatch(fn($payload) => $this->handleEvent($payload));

// OR you can dispatch a job and access payload through the relay object
Relay::request($request)->dispatch(new ExampleJob);
```

Using `event` synchronously processes your action, but you can also use Laravel's `dispatch()` and its methods directly. Atlas will ensure to mark the relay completed or failed depending on the execution. 

[Example with guard and exception handling](./docs/PRD/PRD-Payload-Capture.md#example-with-guard-exception-handling)

---

### Send a Webhook

You can use Laravel's `http()` and it's methods directly.

```php
$payload = ['event' => 'order.created'];

// A simple request
Relay::http()->post('https://api.example.com/webhooks', $payload);

// Tag outbound relays even when starting directly from the manager
Relay::provider('stripe')
    ->setReferenceId('ord-123')
    ->http()
    ->post('https://api.example.com/webhooks', $payload);

// OR with headers
Relay::http()->withHeaders([
    'X-API-KEY' => '1234567890'
])->post('https://api.example.com/webhooks', $payload);
```

Atlas will record the response status and payload of your request (See [Outbound Delivery](./docs/PRD/PRD-Outbound-Delivery.md)).

---

### Auto-Route Dispatch (Inbound → Outbound)

```php
Relay::request($request)->dispatchAutoRoute();
```
Receives a webhook and automatically delivers it to the correct outbound destination using your configured routes and captured payload. (Relates to [Routing](./docs/PRD/PRD-Routing.md))

### Auto-Route Immediate Delivery

```php
$response = Relay::request($request)->autoRouteImmediately();
```
Performs immediate inbound-to-outbound delivery, returning the response inline with the captured payload.  
(Relates to [Outbound Delivery](./docs/PRD/PRD-Outbound-Delivery.md))

---

## 📚 Deep Dives

- [Install Guide](./docs/Install.md)
- [Full API Reference](./docs/Full-API.md)
- [PRD — Atlas Relay](./docs/PRD/PRD-Atlas-Relay.md)
- [PRD — Payload Capture](./docs/PRD/PRD-Payload-Capture.md)
- [PRD — Auto Routing](./docs/PRD/PRD-Routing.md)
- [PRD — Outbound Delivery](./docs/PRD/PRD-Outbound-Delivery.md)
- [PRD — Archiving & Logging](./docs/PRD/PRD-Archiving-and-Logging.md)
- [PRD — Example Usage](./docs/PRD/PRD-Example-Usage.md)

---

## 🤝 Contributing

Please review the [Contributing Guide](./CONTRIBUTING.md) before opening a pull request. It covers the mandatory Pint/PHPStan/test workflow, the PRD-driven standards outlined in [AGENTS.md](./AGENTS.md), and the  branching + Conventional Commit rules we enforce across the project. See [AGENTS.md](./AGENTS.md) for the agent workflow and PRD alignment expectations that apply to every change.

---

## 📘 License

Atlas Relay is open-source software licensed under the [MIT license](./LICENSE).
