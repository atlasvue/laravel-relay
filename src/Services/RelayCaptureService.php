<?php

declare(strict_types=1);

namespace Atlas\Relay\Services;

use Atlas\Relay\Enums\DestinationMethod;
use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Events\RelayCaptured;
use Atlas\Relay\Exceptions\InvalidDestinationUrlException;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Support\RelayContext;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Handles persistence of relay capture metadata according to the Payload Capture PRD.
 */
class RelayCaptureService
{
    public function __construct(private readonly Relay $relay) {}

    public function capture(RelayContext $context): Relay
    {
        $request = $context->request;
        $payload = $context->payload;
        $headers = $this->normalizeHeaders($request);
        $status = $context->status;
        $failureReason = $context->failureReason;
        $validationErrors = $context->validationErrors;

        if ($payload === null && $request !== null) {
            $extracted = $this->extractPayloadFromRequest($request, $validationErrors);

            $payload = $extracted['payload'];
            $validationErrors = $extracted['validationErrors'];

            if ($extracted['status'] !== null) {
                $status = $extracted['status'];
            }

            if ($extracted['failureReason'] !== null) {
                $failureReason = $extracted['failureReason'];
            }
        }

        $maxBytes = (int) config('atlas-relay.capture.max_payload_bytes', 64 * 1024);
        $payloadBytes = $this->payloadSize($payload);

        if ($payloadBytes > $maxBytes) {
            $status = RelayStatus::FAILED;
            $failureReason = RelayFailure::PAYLOAD_TOO_LARGE;
            $payload = null;
            $validationErrors = $this->appendValidationError(
                $validationErrors,
                'payload',
                sprintf('Payload exceeds configured limit of %d bytes.', $maxBytes)
            );
        }

        $destinationUrl = $context->destinationUrl;

        if ($destinationUrl !== null) {
            $length = strlen($destinationUrl);

            if ($length > 255) {
                throw InvalidDestinationUrlException::exceedsMaxLength($length);
            }
        }

        $destinationMethod = $this->determineDestinationMethod($context);

        $attributes = array_merge($this->defaultLifecycleConfig(), $context->lifecycle);
        $attributes = array_merge($attributes, [
            'source' => $this->determineSource($request),
            'headers' => $headers,
            'payload' => $payload,
            'status' => $status,
            'mode' => $context->mode,
            'failure_reason' => $failureReason?->value,
            'response_payload' => null,
            'attempt_count' => Arr::get($context->lifecycle, 'attempt_count', 0),
            'route_id' => $context->routeId,
            'route_identifier' => $context->routeIdentifier,
            'destination_method' => $destinationMethod?->value,
            'destination_url' => $destinationUrl,
        ]);

        if ($validationErrors !== []) {
            $this->reportValidationErrors($validationErrors, $attributes);
        }

        $relay = $this->relay->newQuery()->create($attributes);

        Event::dispatch(new RelayCaptured($relay));
        Log::info('atlas-relay:capture', [
            'relay_id' => $relay->id,
            'status' => $relay->status->label(),
            'status_code' => $relay->status->value,
            'mode' => $relay->mode,
        ]);

        return $relay;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeHeaders(?Request $request): array
    {
        if ($request === null) {
            return [];
        }

        $whitelist = $this->prepareHeaderLookup(config('atlas-relay.capture.header_whitelist', []));
        $sensitive = $this->prepareHeaderLookup(config('atlas-relay.capture.sensitive_headers', []));
        $maskedValue = config('atlas-relay.capture.masked_value', '***');

        $normalized = [];
        foreach ($request->headers->all() as $name => $values) {
            $key = strtolower($name);
            $value = $this->lastValue($values);

            if ($value === null) {
                continue;
            }

            if ($this->shouldMaskHeader($key, $whitelist, $sensitive)) {
                $normalized[$key] = $maskedValue;

                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultLifecycleConfig(): array
    {
        return [
            'is_retry' => false,
            'retry_seconds' => config('atlas-relay.lifecycle.default_retry_seconds'),
            'retry_max_attempts' => config('atlas-relay.lifecycle.default_retry_max_attempts'),
            'attempt_count' => 0,
            'is_delay' => false,
            'delay_seconds' => config('atlas-relay.lifecycle.default_delay_seconds'),
            'timeout_seconds' => config('atlas-relay.lifecycle.default_timeout_seconds'),
            'http_timeout_seconds' => config('atlas-relay.lifecycle.default_http_timeout_seconds'),
            'next_retry_at' => null,
            'processing_at' => null,
            'completed_at' => null,
        ];
    }

    private function determineSource(?Request $request): ?string
    {
        return $request?->ip();
    }

    private function determineDestinationMethod(RelayContext $context): ?DestinationMethod
    {
        $candidate = $context->destinationMethod ?? $context->request?->getMethod();

        if ($candidate === null) {
            return null;
        }

        $method = DestinationMethod::tryFromMixed($candidate);

        if ($method === null) {
            $this->reportInvalidDestinationMethod($candidate, $context);
        }

        return $method;
    }

    /**
     * @param  array<string, array<int, string>>  $validationErrors
     * @param  array<string, mixed>  $attributes
     */
    private function reportValidationErrors(array $validationErrors, array $attributes): void
    {
        Log::warning('atlas-relay:validation', [
            'route_id' => $attributes['route_id'] ?? null,
            'route_identifier' => $attributes['route_identifier'] ?? null,
            'mode' => $attributes['mode'] ?? null,
            'errors' => $validationErrors,
        ]);
    }

    private function reportInvalidDestinationMethod(string $method, RelayContext $context): void
    {
        Log::warning('atlas-relay:destination-method-invalid', [
            'provided' => $method,
            'route_id' => $context->routeId,
            'route_identifier' => $context->routeIdentifier,
            'allowed' => DestinationMethod::values(),
        ]);
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

    /**
     * @param  array<string, bool>  $whitelist
     * @param  array<string, bool>  $sensitive
     */
    private function shouldMaskHeader(string $header, array $whitelist, array $sensitive): bool
    {
        if (isset($whitelist[$header])) {
            return false;
        }

        return isset($sensitive[$header]);
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, bool>
     */
    private function prepareHeaderLookup(array $headers): array
    {
        if ($headers === []) {
            return [];
        }

        return array_fill_keys(array_map('strtolower', $headers), true);
    }

    private function lastValue(mixed $values): ?string
    {
        if (is_array($values)) {
            $values = end($values);
        }

        return $values !== null ? (string) $values : null;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @return array<string, array<int, string>>
     */
    private function appendValidationError(array $errors, string $field, string $message): array
    {
        $errors[$field][] = $message;

        return $errors;
    }

    /**
     * @param  array<string, array<int, string>>  $validationErrors
     * @return array{
     *     payload: mixed,
     *     status: ?RelayStatus,
     *     failureReason: ?RelayFailure,
     *     validationErrors: array<string, array<int, string>>
     * }
     */
    private function extractPayloadFromRequest(Request $request, array $validationErrors): array
    {
        if (! $this->isJsonRequest($request)) {
            return [
                'payload' => $request->all(),
                'status' => null,
                'failureReason' => null,
                'validationErrors' => $validationErrors,
            ];
        }

        $rawBody = (string) $request->getContent();

        if ($rawBody === '') {
            return [
                'payload' => $request->all(),
                'status' => null,
                'failureReason' => null,
                'validationErrors' => $validationErrors,
            ];
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

            return [
                'payload' => $decoded,
                'status' => null,
                'failureReason' => null,
                'validationErrors' => $validationErrors,
            ];
        } catch (JsonException $exception) {
            $validationErrors = $this->appendValidationError(
                $validationErrors,
                'payload',
                sprintf('Invalid JSON payload: %s', $exception->getMessage())
            );

            return [
                'payload' => $rawBody,
                'status' => RelayStatus::FAILED,
                'failureReason' => RelayFailure::INVALID_PAYLOAD,
                'validationErrors' => $validationErrors,
            ];
        }
    }

    private function isJsonRequest(Request $request): bool
    {
        if ($request->isJson()) {
            return true;
        }

        $contentType = $request->headers->get('content-type');

        return $contentType !== null && str_contains(strtolower($contentType), 'json');
    }
}
