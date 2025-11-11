<?php

declare(strict_types=1);

namespace AtlasRelay\Services;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Models\Relay;
use AtlasRelay\Support\RelayContext;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Handles persistence of relay capture metadata according to the Payload Capture PRD.
 */
class RelayCaptureService
{
    public function __construct(private readonly Relay $relay) {}

    public function capture(RelayContext $context): Relay
    {
        $request = $context->request;
        $payload = $this->determinePayload($context);
        $headers = $this->normalizeHeaders($request);
        $status = $context->status;
        $failureReason = $context->failureReason;
        $validationErrors = $context->validationErrors;

        $maxBytes = (int) config('atlas-relay.capture.max_payload_bytes', 64 * 1024);
        $payloadBytes = $this->payloadSize($payload);

        if ($payloadBytes > $maxBytes) {
            $status = 'failed';
            $failureReason = RelayFailure::PAYLOAD_TOO_LARGE;
            $payload = null;
            $validationErrors = $this->appendValidationError(
                $validationErrors,
                'payload',
                sprintf('Payload exceeds configured limit of %d bytes.', $maxBytes)
            );
        }

        $attributes = array_merge($this->defaultLifecycleConfig(), $context->lifecycle);
        $attributes = array_merge($attributes, [
            'request_source' => $this->determineRequestSource($request),
            'headers' => $headers,
            'payload' => $payload,
            'status' => $status,
            'mode' => $context->mode,
            'failure_reason' => $failureReason?->value,
            'meta' => $this->buildMeta($context->meta, $validationErrors, $context->routeHeaders, $context->routeParameters),
            'response_payload' => null,
            'response_payload_truncated' => false,
            'attempt_count' => Arr::get($context->lifecycle, 'attempt_count', 0),
            'route_id' => $context->routeId,
            'route_identifier' => $context->routeIdentifier,
            'destination_type' => $context->destinationType,
            'destination' => $context->destination,
        ]);

        return $this->relay->newQuery()->create($attributes);
    }

    private function determinePayload(RelayContext $context): mixed
    {
        if ($context->payload !== null) {
            return $context->payload;
        }

        if ($context->request !== null) {
            return $context->request->all();
        }

        return null;
    }

    private function normalizeHeaders(?Request $request): array
    {
        if ($request === null) {
            return [];
        }

        $normalized = [];
        foreach ($request->headers->all() as $name => $values) {
            $key = strtolower($name);
            $value = $this->lastValue($values);

            if ($value === null) {
                continue;
            }

            if ($this->shouldMaskHeader($key)) {
                $normalized[$key] = config('atlas-relay.capture.masked_value', '***');

                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function defaultLifecycleConfig(): array
    {
        return [
            'is_retry' => false,
            'retry_seconds' => config('atlas-relay.lifecycle.default_retry_seconds'),
            'retry_max_attempts' => config('atlas-relay.lifecycle.default_retry_max_attempts'),
            'attempt_count' => 0,
            'max_attempts' => null,
            'is_delay' => false,
            'delay_seconds' => config('atlas-relay.lifecycle.default_delay_seconds'),
            'timeout_seconds' => config('atlas-relay.lifecycle.default_timeout_seconds'),
            'http_timeout_seconds' => config('atlas-relay.lifecycle.default_http_timeout_seconds'),
            'last_attempt_duration_ms' => null,
            'retry_at' => null,
            'first_attempted_at' => null,
            'last_attempted_at' => null,
            'processing_started_at' => null,
            'processing_finished_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
            'archived_at' => null,
        ];
    }

    private function determineRequestSource(?Request $request): ?string
    {
        return $request?->ip();
    }

    private function payloadSize(mixed $payload): int
    {
        if ($payload === null) {
            return 0;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return 0;
        }

        return strlen($encoded);
    }

    private function shouldMaskHeader(string $header): bool
    {
        $whitelist = array_map('strtolower', config('atlas-relay.capture.header_whitelist', []));

        if (in_array($header, $whitelist, true)) {
            return false;
        }

        $sensitive = array_map('strtolower', config('atlas-relay.capture.sensitive_headers', []));

        return in_array($header, $sensitive, true);
    }

    private function lastValue(mixed $values): ?string
    {
        if (is_array($values)) {
            $values = end($values);
        }

        return $values !== null ? (string) $values : null;
    }

    private function buildMeta(array $meta, array $validationErrors, array $routeHeaders, array $routeParameters): array
    {
        if (! empty($validationErrors)) {
            $meta['validation_errors'] = $validationErrors;
        }

        if (! empty($routeHeaders)) {
            $meta['route_headers'] = $routeHeaders;
        }

        if (! empty($routeParameters)) {
            $meta['route_parameters'] = $routeParameters;
        }

        return $meta;
    }

    private function appendValidationError(array $errors, string $field, string $message): array
    {
        $errors[$field][] = $message;

        return $errors;
    }
}
