<?php

declare(strict_types=1);

namespace AtlasRelay\Support;

use AtlasRelay\Enums\RelayFailure;
use Illuminate\Http\Request;

/**
 * Immutable snapshot of builder state used by the capture service.
 */
class RelayContext
{
    /**
     * @param  array<string, mixed>  $lifecycle
     * @param  array<string, mixed>  $meta
     * @param  array<string, array<int, string>>  $validationErrors
     * @param  array<string, mixed>  $routeHeaders
     * @param  array<string, string>  $routeParameters
     */
    public function __construct(
        public readonly ?Request $request,
        public readonly mixed $payload,
        public readonly ?string $mode = null,
        public readonly array $lifecycle = [],
        public readonly array $meta = [],
        public readonly ?RelayFailure $failureReason = null,
        public readonly string $status = 'queued',
        public readonly array $validationErrors = [],
        public readonly ?int $routeId = null,
        public readonly ?string $routeIdentifier = null,
        public readonly ?string $destinationType = null,
        public readonly ?string $destination = null,
        public readonly array $routeHeaders = [],
        public readonly array $routeParameters = []
    ) {}
}
