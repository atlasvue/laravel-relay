<?php

declare(strict_types=1);

namespace Atlas\Relay\Guards;

use Atlas\Relay\Contracts\InboundRequestGuardInterface;
use Atlas\Relay\Exceptions\InvalidWebhookHeadersException;
use Atlas\Relay\Exceptions\InvalidWebhookPayloadException;
use Atlas\Relay\Support\InboundRequestGuardContext;
use Illuminate\Support\Arr;

/**
 * Convenience base class for authoring inbound request guards per PRD: Receive Webhook Relay — Guard Validation.
 */
abstract class BaseInboundRequestGuard implements InboundRequestGuardInterface
{
    public function name(): string
    {
        return class_basename(static::class);
    }

    public function captureFailures(): bool
    {
        return true;
    }

    public function validateHeaders(InboundRequestGuardContext $context): void
    {
        // Consumers override when header validation is required.
    }

    public function validatePayload(InboundRequestGuardContext $context): void
    {
        // Consumers override when payload validation is required.
    }

    protected function header(InboundRequestGuardContext $context, string $name): ?string
    {
        return $context->header($name);
    }

    protected function requireHeader(InboundRequestGuardContext $context, string $name): string
    {
        $value = $this->header($context, $name);

        if ($value === null) {
            throw InvalidWebhookHeadersException::fromViolations($this->name(), [
                sprintf('Missing required header [%s].', $name),
            ]);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payloadArray(InboundRequestGuardContext $context): array
    {
        $payload = $context->payload();

        if (! is_array($payload)) {
            throw InvalidWebhookPayloadException::fromViolations($this->name(), ['Payload must be an array.']);
        }

        return $payload;
    }

    protected function requirePayloadKey(InboundRequestGuardContext $context, string $path): mixed
    {
        $payload = $this->payloadArray($context);
        $value = Arr::get($payload, $path);

        if ($value === null) {
            throw InvalidWebhookPayloadException::fromViolations($this->name(), [
                sprintf('Missing payload key [%s].', $path),
            ]);
        }

        return $value;
    }
}
