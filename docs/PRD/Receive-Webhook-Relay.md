# PRD — Receive Webhook Relay

Atlas Relay defines the rules for receiving, validating, normalizing, and capturing inbound webhook requests before any delivery or processing occurs.

## Table of Contents
- [High-Level Flow](#high-level-flow)
- [Guarding](#guarding)
- [Capture Rules](#capture-rules)
- [Schema (Inbound Fields)](#schema-inbound-fields)
- [Failure Codes](#failure-codes)
- [Usage Link](#usage-link)

## High-Level Flow
HTTP Request → Guard (optional) → Normalize → Capture → Event/Dispatch/HTTP

1. Receive request
2. Normalize headers, payload, method, URL, IP
3. Run guard validation
4. Capture relay
5. Forward to delivery (see **Send Webhook Relay**)

## Guarding
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

Full guard examples are in **Example Usage**.

## Capture Rules
Atlas Relay must:

- Initialize relay as `INBOUND`
- Normalize headers and payload
- Mask sensitive headers (`sensitive_headers`)
- Truncate oversized payloads (`payload_max_bytes`)
- Store inbound failure codes where applicable
- Persist the relay **before** any downstream logic

Full capture + delivery examples live in **Example Usage**.

## Schema (Inbound Fields)

| Field                        | Description                            |
|------------------------------|----------------------------------------|
| `type`                       | Always `INBOUND`                       |
| `headers`                    | normalized & masked                    |
| `payload`                    | decoded or raw if JSON fails           |
| `failure_reason`             | set on guard/capture failure           |
| `method`, `url`, `source_ip` | extracted from request                 |
| `processing_at`              | downstream start                       |
| `completed_at`               | lifecycle completion                   |

## Failure Codes
Inbound failure codes:

| Code | Meaning               |
|------|-----------------------|
| 105  | INVALID_PAYLOAD       |
| 108  | INVALID_GUARD_HEADERS |
| 109  | INVALID_GUARD_PAYLOAD |
| 101  | PAYLOAD_TOO_LARGE     |

## Also See
- [Atlas Relay](./Atlas-Relay.md)
- [Send Webhook Relay](./Send-Webhook-Relay.md)
- [Archiving & Logging](./Archiving-and-Logging.md)
- [Example Usage](./Example-Usage.md)
