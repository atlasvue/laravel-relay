<?php

declare(strict_types=1);

namespace Atlas\Relay\Support;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Illuminate\Http\Request;

/**
 * Immutable snapshot of builder state used by the capture service.
 */
class RelayContext
{
    /**
     * @param  array<string, mixed>  $lifecycle
     * @param  array<string, array<int, string>>  $validationErrors
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly ?Request $request,
        public readonly mixed $payload,
        public readonly ?string $mode = null,
        public readonly array $lifecycle = [],
        public readonly ?RelayFailure $failureReason = null,
        public readonly RelayStatus $status = RelayStatus::QUEUED,
        public readonly array $validationErrors = [],
        public readonly ?int $routeId = null,
        public readonly ?string $routeIdentifier = null,
        public readonly ?string $method = null,
        public readonly ?string $url = null,
        public readonly ?string $provider = null,
        public readonly ?string $referenceId = null,
        public readonly array $headers = [],
    ) {}
}
