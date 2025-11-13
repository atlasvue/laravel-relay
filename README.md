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

```php
// The most common way
Relay::request($request)->event(fn($payload) => $this->handleEvent($payload));

// OR you can dispatch for async processing
// OR you can dispatch a job and access payload through the relay object
Relay::request($request)->dispatch(new ExampleJob);
```

`Relay::request($request)` automatically grabs the inbound payload (JSON or form data), so your event callbacks immediately receive the decoded payload. (See [Payload Capture](./docs/PRD/PRD-Payload-Capture.md)).

---

### Send a Webhook
```php
$payload = ['event' => 'order.created'];

// A simple request
Relay::http()->post('https://api.example.com/webhooks', $payload);

// OR with headers
Relay::http()->withHeaders([
    'X-API-KEY' => '1234567890'
])->post('https://api.example.com/webhooks', $payload);
```
You can use the Laravel `http()` methods you're most likely already using. When you start from `Relay::request($request)`, inbound headers are copied automatically—just configure the HTTP client with `withHeaders()`, `accept()`, etc., and Atlas will record those values for you. (See [Outbound Delivery](./docs/PRD/PRD-Outbound-Delivery.md)).

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
