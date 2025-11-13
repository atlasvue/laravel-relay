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
Relay::request($request)->dispatchEvent(fn($payload) => $this->handleEvent($payload));

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
You can use the Laravel `http()` methods you're most likely already using. When you start from `Relay::request($request)`, inbound headers are copied automatically—just call `->setHeaders()` on that builder before `->http()` if you need to merge your own values. (See [Outbound Delivery](./docs/PRD/PRD-Outbound-Delivery.md)).

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

Need the full lifecycle, routing, or automation specs? The PRDs capture every rule in detail:

- **Lifecycle & statuses** — [PRD — Atlas Relay → Status Lifecycle](./docs/PRD/PRD-Atlas-Relay.md#status-lifecycle)
- **Retry / delay / timeout logic** — [PRD — Outbound Delivery → Retry, Delay & Timeout](./docs/PRD/PRD-Outbound-Delivery.md#retry-delay--timeout)
- **Routing behavior & cache rules** — [PRD — Auto Routing](./docs/PRD/PRD-Routing.md#autorouting-behavior)
- **Observability, logging & retention** — [PRD — Archiving & Logging](./docs/PRD/PRD-Archiving-and-Logging.md#observability)
- **Archiving & purge schedules** — [PRD — Archiving & Logging → Archiving Process](./docs/PRD/PRD-Archiving-and-Logging.md#archiving-process)
- **Automation jobs & cadence** — [PRD — Atlas Relay → Automation Jobs](./docs/PRD/PRD-Atlas-Relay.md#automation-jobs)
- **Configuration reference** — [Full API Guide](./docs/Full-API.md#configuration-reference-configatlas-relayphp)
- **Failure / error mapping** — [PRD — Outbound Delivery → Failure Reason Enum](./docs/PRD/PRD-Outbound-Delivery.md#failure-reason-enum)

All PRDs live under [`docs/PRD`](./docs/PRD); treat them as the source of truth when implementing or troubleshooting.

---

## 🛠 Artisan Helpers

| Command                                   | Description                                      |
|-------------------------------------------|--------------------------------------------------|
| `atlas-relay:routes:seed path.json`       | Seed routes from a JSON file.                    |
| `atlas-relay:relay:inspect {id}`          | Print relay or archived relay state (JSON).      |
| `atlas-relay:relay:restore {id}`          | Move an archived relay back into the live table. |
| `atlas-relay:retry-overdue`               | Requeue relays whose retry window elapsed.       |
| `atlas-relay:requeue-stuck`               | Requeue relays stuck in `processing`.            |
| `atlas-relay:enforce-timeouts`            | Mark long-running relays as timed out.           |
| `atlas-relay:archive` / `:purge-archives` | Manage archiving and purge retention.            |

---

## 🤝 Contributing

Please review the [Contributing Guide](./CONTRIBUTING.md) before opening a pull request. It covers the mandatory Pint/PHPStan/test workflow, the PRD-driven standards outlined in [AGENTS.md](./AGENTS.md), and the  branching + Conventional Commit rules we enforce across the project. See [AGENTS.md](./AGENTS.md) for the agent workflow and PRD alignment expectations that apply to every change.

---

## 📘 License

Atlas Relay is open-source software licensed under the [MIT license](./LICENSE).
