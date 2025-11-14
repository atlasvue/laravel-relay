# PRD — Receive Webhook Relay

## Purpose
Defines **how Atlas Relay receives, validates, and captures inbound webhooks**.  
This PRD covers *only* the inbound side: guarding, normalization, and capture.

All execution patterns (events, jobs, HTTP) and full usage examples live in:  
**[Example Usage](./Example-Usage.md)**

Outbound delivery rules are documented in:  
**[Send Webhook Relay](./Send-Webhook-Relay.md)**

Full API details are in:  
**[Full API Reference](../Full-API.md)**

---

## High‑Level Flow
**HTTP Request → Guard (optional) → Normalize → Capture → Event/Dispatch/HTTP**

1. Receive request
2. Normalize headers, payload, URL, IP
3. Run guard validation
4. Capture relay
5. Forward to delivery (see **Send Webhook Relay PRD**)

---

## Guarding (Optional)
Full guard examples (basic + advanced), including inline comments explaining *why* certain patterns exist, are located in:  
**[Example Usage](./Example-Usage.md#14-advanced-guard-example-with-commentary)**

Guards may:
- Require headers
- Validate payloads with Laravel’s validator
- Fail with custom messages
- Choose whether failures should be captured or ignored

Guard Interfaces:
```
Atlas\Relay\Contracts\InboundRequestGuardInterface
```

Guard Base Class:
```
Atlas\Relay\Guards\BaseInboundRequestGuard
```

---

## Capture Rules
Atlas Relay must:

- Initialize relay as `INBOUND`
- Normalize headers/payload
- Truncate oversized payloads (`payload_max_bytes`)
- Mask sensitive headers (`sensitive_headers`)
- Store `INVALID_PAYLOAD`, `INVALID_GUARD_HEADERS`, etc.
- Persist the relay **before** any business logic or outbound actions

Full examples of capture + delivery flow:  
**[Example Usage](./Example-Usage.md)**

---

## Schema (Inbound Fields)
(Complete schema is in **[Full API Reference](../Full-API.md)**)

| Field                        | Description                            |
|------------------------------|----------------------------------------|
| `type`                       | Always `INBOUND` for incoming webhooks |
| `headers`                    | normalized & masked                    |
| `payload`                    | decoded or raw if JSON fails           |
| `failure_reason`             | set on guard/capture failure           |
| `method`, `url`, `source_ip` | extracted from request                 |
| `processing_at`              | downstream start                       |
| `completed_at`               | lifecycle completion                   |

---

## Failure Codes (Inbound)
Full failure enum in **[Full API Reference](../Full-API.md#failure-reason-enum)**

| Code | Meaning               |
|------|-----------------------|
| 105  | INVALID_PAYLOAD       |
| 108  | INVALID_GUARD_HEADERS |
| 109  | INVALID_GUARD_PAYLOAD |
| 101  | PAYLOAD_TOO_LARGE     |

---

## Usage Link (Required Reading)
For production-ready examples—including:

- Full guard implementations
- Exception handling patterns
- Multi‑tenant validation logic
- Header/payload rule enforcement
- End‑to‑end webhook → event/job/http flows

See:  
**[Example Usage](./Example-Usage.md)**

This PRD defines the inbound boundary; all examples and delivery patterns live in the usage document for clarity.
