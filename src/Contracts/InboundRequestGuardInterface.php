<?php

declare(strict_types=1);

namespace Atlas\Relay\Contracts;

use Atlas\Relay\Support\InboundRequestGuardContext;

/**
 * Defines the inbound webhook guard contract per PRD: Receive Webhook Relay — Guard Validation.
 */
interface InboundRequestGuardInterface
{
    /**
     * Human-readable name shown in exception payloads and relay failure metadata.
     */
    public function name(): string;

    /**
     * Whether Atlas should persist and flag failed attempts when this guard blocks a request.
     */
    public function captureFailures(): bool;

    /**
     * Validate inbound headers (authentication/signatures) and throw InvalidWebhookHeadersException when rejected.
     */
    public function validateHeaders(InboundRequestGuardContext $context): void;

    /**
     * Validate normalized payload data and throw InvalidWebhookPayloadException when rejected.
     */
    public function validatePayload(InboundRequestGuardContext $context): void;
}
