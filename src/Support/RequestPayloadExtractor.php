<?php

declare(strict_types=1);

namespace Atlas\Relay\Support;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Illuminate\Http\Request;
use JsonException;

/**
 * Normalizes inbound HTTP request bodies into payload arrays as defined by the Receive Webhook Relay PRD.
 *
 * Defined by PRD: Receive Webhook Relay — Payload Handling.
 */
class RequestPayloadExtractor
{
    /**
     * @param  array<string, array<int, string>>  $validationErrors
     * @return array{
     *     payload: mixed,
     *     status: RelayStatus|null,
     *     failureReason: RelayFailure|null,
     *     validationErrors: array<string, array<int, string>>
     * }
     */
    public function extract(Request $request, array $validationErrors = []): array
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

    /**
     * @param  array<string, array<int, string>>  $validationErrors
     * @return array<string, array<int, string>>
     */
    private function appendValidationError(array $validationErrors, string $field, string $message): array
    {
        $validationErrors[$field] ??= [];
        $validationErrors[$field][] = $message;

        return $validationErrors;
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
