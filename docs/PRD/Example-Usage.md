# PRD — Atlas Relay Example Usage

This document provides complete, practical examples demonstrating how to use Atlas Relay across inbound handling, guards, job dispatching, chaining, HTTP delivery, and accessing relay context inside jobs.

## Table of Contents
- [Receiving Webhooks](#receiving-webhooks)
- [Using Guards](#using-guards)
- [Advanced Guard Example](#advanced-guard-example)
- [Dispatching Jobs](#dispatching-jobs)
- [Chaining Jobs](#chaining-jobs)
- [Direct HTTP](#direct-http)
- [Accessing Relay in Jobs](#accessing-relay-in-jobs)

## Receiving Webhooks
```php
use Atlas\Relay\Facades\Relay;

public function __invoke(Request $request)
{
    Relay::request($request)
        ->event(fn ($payload) => $this->process($payload));

    return response()->json(['ok' => true]);
}
```

## Using Guards
```php
Relay::request($request)
    ->provider('stripe')
    ->guard(StripeWebhookGuard::class)
    ->event(fn ($payload) => $this->handleEvent($payload));
```

## Advanced Guard Example
```php
use Atlas\Relay\Guards\BaseInboundRequestGuard;
use Atlas\Relay\Guards\InboundRequestGuardContext;
use Atlas\Relay\Exceptions\InvalidWebhookHeadersException;

class TenantWebhookGuard extends BaseInboundRequestGuard
{
    public function validate(InboundRequestGuardContext $context): void
    {
        $signature = $context->header('X-Tenant-Signature');

        if ($signature === null) {
            $context->failHeaders(['Tenant signature header missing.']);
        }

        $tenantId = data_get($context->payload(), 'meta.tenant_id');
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant) {
            $context->failPayload([sprintf('Unknown tenant id [%s].', $tenantId ?? 'null')]);
        }

        if (! hash_equals($tenant->secret, $signature)) {
            throw InvalidWebhookHeadersException::fromViolations(
                'TenantWebhookGuard',
                ['Signature mismatch for tenant.']
            );
        }
    }
}
```

## Dispatching Jobs
```php
$pending = Relay::request($request)
    ->dispatch(new ExampleJob($request->all()));

$pending->onQueue('critical')
    ->onConnection('redis')
    ->delay(now()->addMinutes(5));
```

## Chaining Jobs
```php
Relay::request($request)
    ->dispatchChain([
        new PrepareDataJob(),
        new DeliverToPartnerJob(),
        new NotifyDownstreamJob(),
    ])
    ->onQueue('pipeline');
```

## Direct HTTP
```php
Relay::http()
    ->withHeaders(['X-App' => 'Atlas'])
    ->timeout(30)
    ->retry(3, 2000)
    ->post('https://api.partner.com/receive', $data);
```

## Accessing Relay in Jobs
```php
use Atlas\Relay\Support\RelayJobHelper;

class ExampleJob implements ShouldQueue
{
    public function handle()
    {
        $relay = app(RelayJobHelper::class)->relay();
    }
}
```

## Also See
- [Atlas Relay](./Atlas-Relay.md)
- [Receive Webhook Relay](./Receive-Webhook-Relay.md)
- [Send Webhook Relay](./Send-Webhook-Relay.md)
- [Archiving & Logging](./Archiving-and-Logging.md)
