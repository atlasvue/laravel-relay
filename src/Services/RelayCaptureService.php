<?php

declare(strict_types=1);

namespace Atlas\Relay\Services;

use Atlas\Relay\Enums\HttpMethod;
use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Exceptions\InvalidDestinationUrlException;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Support\RelayContext;
use Atlas\Relay\Support\RequestPayloadExtractor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles persistence of relay capture metadata according to the Receive Webhook Relay PRD.
 */
class RelayCaptureService
{
    private const MASKED_HEADER_VALUE = '*********';

    public function __construct(
        private readonly Relay $relay,
        private readonly RequestPayloadExtractor $payloadExtractor
    ) {}

    public function capture(RelayContext $context): Relay
    {
        $request = $context->request;
        $payload = $context->payload;
        $headers = $request !== null
            ? $this->normalizeHeadersFromRequest($request)
            : [];

        if ($context->headers !== []) {
            $manualHeaders = $this->normalizeHeaderArray($context->headers);
            $headers = $headers === []
                ? $manualHeaders
                : array_merge($headers, $manualHeaders);
        }
        $status = $context->status;
        $failureReason = $context->failureReason;
        $validationErrors = $context->validationErrors;

        if ($payload === null && $request !== null) {
            $extracted = $this->payloadExtractor->extract($request, $validationErrors);

            $payload = $extracted['payload'];
            $validationErrors = $extracted['validationErrors'];

            if ($extracted['status'] !== null) {
                $status = $extracted['status'];
            }

            if ($extracted['failureReason'] !== null) {
                $failureReason = $extracted['failureReason'];
            }
        }

        $maxBytes = (int) config('atlas-relay.payload.max_bytes', 64 * 1024);
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

        $url = $context->url ?? $context->request?->fullUrl();

        if ($url !== null) {
            $length = strlen($url);

            if ($length > 255) {
                throw InvalidDestinationUrlException::exceedsMaxLength($length);
            }
        }

        $method = $this->determineMethod($context);

        $attributes = [
            'type' => $context->type->value,
            'status' => $status,
            'provider' => $context->provider,
            'reference_id' => $context->referenceId,
            'source_ip' => $this->determineSourceIp($request),
            'headers' => $headers,
            'payload' => $payload,
            'failure_reason' => $failureReason?->value,
            'response_payload' => null,
            'method' => $method?->value,
            'url' => $url,
            'processing_at' => null,
            'completed_at' => null,
        ];

        if ($validationErrors !== []) {
            $this->reportValidationErrors($validationErrors, $attributes);
        }

        $relay = $this->relay->newQuery()->create($attributes);

        return $relay;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeHeadersFromRequest(Request $request): array
    {
        $raw = [];

        foreach ($request->headers->all() as $name => $values) {
            $value = $this->lastValue($values);

            if ($value === null) {
                continue;
            }

            $raw[$name] = $value;
        }

        return $this->normalizeHeaderArray($raw);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function normalizeHeaderArray(array $headers): array
    {
        if ($headers === []) {
            return [];
        }

        $sensitive = $this->prepareHeaderLookup(config('atlas-relay.capture.sensitive_headers', []));

        $normalized = [];

        foreach ($headers as $name => $value) {
            $key = strtolower($name);
            $stringValue = $this->stringifyHeaderValue($value);

            if ($stringValue === null) {
                continue;
            }

            if ($this->shouldMaskHeader($key, $sensitive)) {
                $normalized[$key] = self::MASKED_HEADER_VALUE;

                continue;
            }

            $normalized[$key] = $stringValue;
        }

        return $normalized;
    }

    private function determineSourceIp(?Request $request): ?string
    {
        return $this->normalizeSourceIp($request?->ip());
    }

    private function normalizeSourceIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        $ip = trim($ip);

        if ($ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }

        if (str_contains($ip, ':') && preg_match('/(\d{1,3}\.){3}\d{1,3}$/', $ip, $matches) === 1) {
            $candidate = $matches[0];

            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $candidate;
            }
        }

        return null;
    }

    private function determineMethod(RelayContext $context): ?HttpMethod
    {
        $candidate = $context->method ?? $context->request?->getMethod();

        if ($candidate === null) {
            return null;
        }

        $method = HttpMethod::tryFromMixed($candidate);

        if ($method === null) {
            $this->reportInvalidMethod($candidate, $context);
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
            'type' => $attributes['type'] ?? null,
            'provider' => $attributes['provider'] ?? null,
            'reference_id' => $attributes['reference_id'] ?? null,
            'errors' => $validationErrors,
        ]);
    }

    private function reportInvalidMethod(string $method, RelayContext $context): void
    {
        Log::warning('atlas-relay:method-invalid', [
            'provided' => $method,
            'allowed' => HttpMethod::values(),
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
     * @param  array<string, bool>  $sensitive
     */
    private function shouldMaskHeader(string $header, array $sensitive): bool
    {
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

    private function stringifyHeaderValue(mixed $value): ?string
    {
        if (is_array($value)) {
            if ($value === []) {
                return null;
            }

            $value = end($value);
        }

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return null;
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
}
