<?php

declare(strict_types=1);

namespace AtlasRelay\Support;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Models\Relay;
use AtlasRelay\Services\RelayLifecycleService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Proxy around Laravel's HTTP client that enforces PRD rules and records relay lifecycle data.
 */
class RelayHttpClient
{
    /**
     * @var array<string>
     */
    private array $verbs = [
        'get', 'post', 'put', 'patch', 'delete', 'head',
    ];

    public function __construct(
        private PendingRequest $pendingRequest,
        private readonly RelayLifecycleService $lifecycle,
        private readonly Relay $relay
    ) {}

    public function __call(string $method, array $arguments)
    {
        $method = strtolower($method);

        if (in_array($method, $this->verbs, true)) {
            return $this->send($method, ...$arguments);
        }

        $result = $this->pendingRequest->{$method}(...$arguments);

        if ($result instanceof PendingRequest) {
            $this->pendingRequest = $result;

            return $this;
        }

        return $result;
    }

    private function send(string $method, ...$arguments): Response
    {
        $url = $arguments[0] ?? null;

        if (! is_string($url)) {
            throw new RuntimeException('HTTP relay calls require a target URL.');
        }

        $this->assertHttps($url);

        $this->pendingRequest = $this->pendingRequest->withHeaders(
            $this->relay->meta['route_headers'] ?? []
        );

        $relay = $this->lifecycle->startAttempt($this->relay);
        $startedAt = microtime(true);

        try {
            /** @var Response $response */
            $response = $this->pendingRequest->{$method}(...$arguments);
        } catch (ConnectionException $exception) {
            $duration = $this->durationSince($startedAt);
            $failure = $this->failureForConnectionException($exception);
            $this->lifecycle->markFailed($relay, $failure, [], $duration);

            throw $exception;
        } catch (RequestException $exception) {
            $duration = $this->durationSince($startedAt);
            $this->lifecycle->markFailed($relay, RelayFailure::OUTBOUND_HTTP_ERROR, [], $duration);

            throw $exception;
        }

        $duration = $this->durationSince($startedAt);

        $this->evaluateRedirects($url, $response, $relay, $duration);

        [$payload, $truncated] = $this->normalizePayload($response);

        $this->lifecycle->recordResponse($relay, $response->status(), $payload, $truncated);

        if ($response->successful()) {
            $this->lifecycle->markCompleted($relay, [], $duration);
        } else {
            $this->lifecycle->markFailed($relay, RelayFailure::OUTBOUND_HTTP_ERROR, [], $duration);
        }

        return $response;
    }

    private function assertHttps(string $url): void
    {
        $enforce = (bool) config('atlas-relay.http.enforce_https', true);

        if (! $enforce) {
            return;
        }

        if (Str::startsWith(strtolower($url), 'https://')) {
            return;
        }

        throw new RuntimeException('Atlas Relay HTTP deliveries require HTTPS targets.');
    }

    private function evaluateRedirects(string $originalUrl, Response $response, Relay $relay, int $duration): void
    {
        $stats = $response->handlerStats();
        $redirectCount = (int) ($stats['redirect_count'] ?? 0);
        $maxRedirects = (int) config('atlas-relay.http.max_redirects', 3);

        if ($redirectCount > $maxRedirects) {
            $this->lifecycle->markFailed($relay, RelayFailure::TOO_MANY_REDIRECTS, [], $duration);

            throw new RuntimeException('Redirect limit exceeded for relay HTTP delivery.');
        }

        $effective = (string) ($response->effectiveUri() ?? $originalUrl);

        $originalHost = parse_url($originalUrl, PHP_URL_HOST);
        $effectiveHost = parse_url($effective, PHP_URL_HOST);

        if ($effectiveHost !== null && $originalHost !== null && ! hash_equals($originalHost, $effectiveHost)) {
            $this->lifecycle->markFailed($relay, RelayFailure::REDIRECT_HOST_CHANGED, [], $duration);

            throw new RuntimeException('Redirect attempted to a different host.');
        }
    }

    private function durationSince(float $startedAt): int
    {
        return (int) max(0, round((microtime(true) - $startedAt) * 1000));
    }

    private function failureForConnectionException(ConnectionException $exception): RelayFailure
    {
        return Str::contains(strtolower($exception->getMessage()), 'timed out')
            ? RelayFailure::CONNECTION_TIMEOUT
            : RelayFailure::CONNECTION_ERROR;
    }

    private function truncatePayload(?string $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $maxBytes = (int) config('atlas-relay.http.max_response_bytes', 16 * 1024);

        return strlen($payload) > $maxBytes
            ? substr($payload, 0, $maxBytes)
            : $payload;
    }

    /**
     * @return array{0:mixed,1:bool}
     */
    private function normalizePayload(Response $response): array
    {
        $json = $response->json();

        if (is_array($json)) {
            return [$json, false];
        }

        $body = $response->body();
        $payload = $this->truncatePayload($body);
        $truncated = strlen((string) $body) > strlen((string) ($payload ?? ''));

        return [$payload, $truncated];
    }
}
