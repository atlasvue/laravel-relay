<?php

declare(strict_types=1);

namespace AtlasRelay\Routing;

use Illuminate\Http\Request;

/**
 * Normalized routing context composed from the inbound request and relay payload.
 */
class RouteContext
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public readonly ?string $method,
        public readonly ?string $path,
        public readonly ?Request $request = null,
        public readonly array $payload = [],
        public readonly array $headers = []
    ) {}

    public static function fromRequest(?Request $request, mixed $payload = null): self
    {
        $payloadData = is_array($payload) ? $payload : (array) $payload;

        return new self(
            method: self::normalizeMethod($request?->getMethod()),
            path: self::normalizePath($request?->getPathInfo()),
            request: $request,
            payload: $payloadData,
            headers: $request?->headers->all() ?? []
        );
    }

    public function normalizedMethod(): ?string
    {
        return self::normalizeMethod($this->method ?? $this->request?->getMethod());
    }

    public function normalizedPath(): ?string
    {
        return self::normalizePath($this->path ?? $this->request?->getPathInfo());
    }

    private static function normalizeMethod(?string $method): ?string
    {
        return $method !== null ? strtoupper($method) : null;
    }

    private static function normalizePath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $normalized = '/'.ltrim($path, '/');

        return $normalized === '//' ? '/' : $normalized;
    }
}
