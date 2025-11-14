<?php

declare(strict_types=1);

namespace Atlas\Relay\Support;

use Atlas\Relay\Models\Relay;
use Illuminate\Http\Request;

/**
 * Value object exposing normalized inbound request data to guard classes per PRD: Receive Webhook Relay — Guard Validation.
 *
 * @phpstan-type HeaderMap array<string, string>
 */
class InboundRequestGuardContext
{
    /**
     * @param  HeaderMap  $headers
     */
    public function __construct(
        private readonly Request $request,
        private readonly array $headers,
        private readonly mixed $payload,
        private readonly ?Relay $relay = null
    ) {
        $this->headerLookup = $this->normalizeHeaderLookup($headers);
    }

    public function request(): Request
    {
        return $this->request;
    }

    /**
     * Returns the normalized header list captured during Relay::request().
     *
     * @return HeaderMap
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Performs a case-insensitive header lookup.
     */
    public function header(string $name): ?string
    {
        $normalized = strtolower($name);

        return $this->headerLookup[$normalized] ?? null;
    }

    /**
     * Returns the normalized payload array/object decoded from the request.
     */
    public function payload(): mixed
    {
        return $this->payload;
    }

    /**
     * Relay model captured prior to guard execution when captureFailures() is true.
     */
    public function relay(): ?Relay
    {
        return $this->relay;
    }

    /**
     * @param  HeaderMap  $headers
     * @return array<string, string>
     */
    private function normalizeHeaderLookup(array $headers): array
    {
        $lookup = [];

        foreach ($headers as $name => $value) {
            $lookup[strtolower($name)] = $value;
        }

        return $lookup;
    }

    /** @var array<string, string> */
    private array $headerLookup = [];
}
