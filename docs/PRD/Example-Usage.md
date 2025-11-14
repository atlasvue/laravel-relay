# PRD — Atlas Relay Example Usage

## Overview
This document contains **all practical usage patterns** for Atlas Relay, including:

- Receiving + guarding webhooks
- Event / job / HTTP execution
- Advanced guard logic with inline commentary
- Accessing relay context in jobs

Inbound flow rules are defined in:  
**[Receive Webhook Relay](./Receive-Webhook-Relay.md)**

Outbound delivery rules are defined in:  
**[Send Webhook Relay](./Send-Webhook-Relay.md)**

Full API:  
**[Full API Reference](../Full-API.md)**

---

# 1. Receiving Webhooks

## 1.1 Basic Webhook Handling

```php
use Atlas\Relay\Facades\Relay;

public function __invoke(Request $request)
{
    Relay::request($request)
        ->event(fn ($payload) => $this->process($payload));

    return response()->json(['ok' => true]);
}
```

---

## 1.2 Using a Guard (Recommended)

```php
use Atlas\Relay\Facades\Relay;
use App\Guards\StripeWebhookGuard;
use Atlas\Relay\Exceptions\InvalidWebhookHeadersException;
use Atlas\Relay\Exceptions\InvalidWebhookPayloadException;

public function __invoke(Request $request)
{
    try {
        Relay::request($request)
            ->provider('stripe')
            ->guard(StripeWebhookGuard::class)
            ->event(fn ($payload) => $this->handleEvent($payload));

        return response()->json(['message' => 'ok']);
    } catch (InvalidWebhookHeadersException $exception) {
        return response()->json(['message' => 'Forbidden'], 403);
    } catch (InvalidWebhookPayloadException $exception) {
        return response()->json(['message' => $exception->getMessage()], 422);
    }
}
```

---

## 1.3 Basic Guard Class Example

```php
use Atlas\Relay\Guards\BaseInboundRequestGuard;
use Atlas\Relay\Support\InboundRequestGuardContext;

class StripeWebhookGuard extends BaseInboundRequestGuard
{
    public function validate(InboundRequestGuardContext $context): void
    {
        $context->requireHeader('Stripe-Signature', env('STRIPE_WEBHOOK_SIGNATURE'));
        $context->requireHeader('X-Relay-Request');
        
        // laravel validator example:
        $context->validatePayload([
            'id' => ['required', 'string'],
            'type' => ['required', 'string'],
            'event.order.id' => ['required', 'string'],
        ]);
    }
}
```

---

## 1.4 Advanced Guard Example (with Commentary)

```php
use Atlas\Relay\Exceptions\InvalidWebhookHeadersException;
use Atlas\Relay\Exceptions\InvalidWebhookPayloadException;
use Atlas\Relay\Guards\BaseInboundRequestGuard;
use Atlas\Relay\Support\InboundRequestGuardContext;
use App\Models\Tenant;

class TenantWebhookGuard extends BaseInboundRequestGuard
{
    public function validate(InboundRequestGuardContext $context): void
    {
        // 1. Inspect raw header directly
        $signature = $context->header('X-Tenant-Signature');

        if ($signature === null) {
            // Custom failure explaining EXACTLY why request is rejected
            $context->failHeaders(['Tenant signature header missing.']);
        }

        // 2. Pull tenant ID from payload
        $tenantId = data_get($context->payload(), 'meta.tenant_id');

        // Check tenant existence in your own system
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant) {
            // Fail with detailed, audit‑friendly explanation
            $context->failPayload([sprintf('Unknown tenant id [%s].', $tenantId ?? 'null')]);
        }

        // 3. Validate signature matches tenant secret
        if (! hash_equals($tenant->secret, $signature)) {
            throw InvalidWebhookHeadersException::fromViolations(
                'TenantWebhookGuard',
                ['Signature mismatch for tenant.']
            );
        }

        return;
    }
}
```

**Why this matters:**

- Demonstrates **header + payload + model validation** in one guard
- Shows **how to create meaningful audit logs**
- Shows **when to use helpers vs exceptions**
- Models real multi‑tenant webhook security patterns

---

# 2. Dispatching Jobs

```php
$pending = Relay::request($request)
    ->dispatch(new ExampleJob($request->all()));

$pending->onQueue('critical')
    ->onConnection('redis')
    ->delay(now()->addMinutes(5));
```

---

# 3. Chaining Jobs

```php
Relay::request($request)
    ->dispatchChain([
        new PrepareDataJob(),
        new DeliverToPartnerJob(),
        new NotifyDownstreamJob(),
    ])
    ->onQueue('pipeline');
```

---

# 4. Direct HTTP

```php
Relay::http()
    ->withHeaders(['X-App' => 'Atlas'])
    ->timeout(30)
    ->retry(3, 2000)
    ->post('https://api.partner.com/receive', $data);
```

---

# 5. Accessing Relay in Jobs

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

---

This document centralizes **all usage examples**.  

For inbound rules, see **[Receive Webhook Relay](./Receive-Webhook-Relay.md)**.  

For outbound rules, see **[Send Webhook Relay](./Send-Webhook-Relay.md)**.
