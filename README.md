# Atlas Relay

**Atlas Relay** is a Laravel package providing a unified, reliable system for receiving webhooks, processing actions, and sending outbound HTTP requests — all with full lifecycle tracking and auditability.

## Table of Contents
- [Overview](#overview)
- [Installation](#installation)
- [Receive Webhooks](#receive-webhooks)
- [Send Webhooks](#send-webhooks)
- [Also See](#also-see)
- [Contributing](#contributing)
- [License](#license)

## Overview
Atlas Relay simplifies webhook and payload handling by ensuring every inbound and outbound request is captured, validated, executed, and recorded. It removes the fragility of ad‑hoc webhook handling and replaces it with a consistent, observable pipeline.

Key guarantees:
- Payloads are always **captured before processing**.
- Supports events, jobs, chains, and outbound HTTP.
- Complete auditability, retry awareness, and lifecycle tracking.
- Simple API with powerful underlying behavior.

## Installation
```bash
composer require atlas-php/relay
```

For publishing config, migrations, and scheduler setup:  
[Install Guide](./docs/Install.md)

## Receive Webhooks
```php
Relay::request($request)
    ->event(fn ($payload) => $this->handleEvent($payload));
```

Async job:
```php
Relay::request($request)->dispatch(new ExampleJob);
```

With guard:
```php
Relay::request($request)
    ->guard(StripeGuard::class)
    ->event(fn ($payload) => $this->handleEvent($payload));
```

More inbound examples:  
[Receive Webhook Relay](./docs/PRD/Receive-Webhook-Relay.md)

## Send Webhooks
```php
Relay::http()->post('https://api.example.com/webhooks', [
    'event' => 'order.created',
]);
```

With provider, reference, headers, retries, and timeout:
```php
Relay::provider('stripe')
    ->setReferenceId('ord-123')
    ->http()
    ->withHeaders(['X-API-KEY' => '123'])
    ->retry(3, 500)
    ->timeout(10)
    ->post('https://partner.com/ingest', $payload);
```

More outbound examples:  
[Send Webhook Relay](./docs/PRD/Send-Webhook-Relay.md)

## Also See
- [Atlas Relay Model](./docs/PRD/Atlas-Relay.md)
- [Receive Webhook Relay](./docs/PRD/Receive-Webhook-Relay.md)
- [Send Webhook Relay](./docs/PRD/Send-Webhook-Relay.md)
- [Archiving & Logging](./docs/PRD/Archiving-and-Logging.md)
- [Example Usage](./docs/PRD/Example-Usage.md)
- [Full API Reference](./docs/Full-API.md)
- [Install Guide](./docs/Install.md)

## Contributing
See the [Contributing Guide](./CONTRIBUTING.md).  
All work must align with PRDs and agent workflow rules defined in [AGENTS.md](./AGENTS.md).

## License
MIT — see [LICENSE](./LICENSE).
