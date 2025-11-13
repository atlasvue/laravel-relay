<?php

declare(strict_types=1);

namespace Atlas\Relay\Support;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Exceptions\RelayHttpException;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Services\RelayLifecycleService;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Proxy around Laravel's HTTP client that enforces PRD rules and records relay lifecycle data.
 *
 * @method Response get(string $url, mixed ...$arguments)
 * @method Response post(string $url, mixed ...$arguments)
 * @method Response put(string $url, mixed ...$arguments)
 * @method Response patch(string $url, mixed ...$arguments)
 * @method Response delete(string $url, mixed ...$arguments)
 * @method Response head(string $url, mixed ...$arguments)
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

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
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

    /**
     * @param  mixed  ...$arguments
     */
    private function send(string $method, ...$arguments): Response
    {
        $url = $arguments[0] ?? null;

        try {
            if (! is_string($url)) {
                throw new RelayHttpException(
                    'HTTP relay calls require a target URL.',
                    RelayFailure::OUTBOUND_HTTP_ERROR
                );
            }

            $this->assertHttps($url);
        } catch (RelayHttpException $exception) {
            $failure = $exception->failure() ?? RelayFailure::OUTBOUND_HTTP_ERROR;

            $this->lifecycle->markFailed($this->relay, $failure);
            $this->lifecycle->recordResponse($this->relay, null, $exception->getMessage());

            throw $exception;
        }

        $this->pendingRequest = $this->pendingRequest->withHeaders(
            $this->relay->meta['route_headers'] ?? []
        );

        $relay = $this->lifecycle->startAttempt($this->relay);
        $startedAt = microtime(true);
        $this->applyRedirectGuards($url, $relay, $startedAt);

        try {
            /** @var Response $response */
            $response = $this->pendingRequest->{$method}(...$arguments);
        } catch (ConnectionException $exception) {
            $duration = $this->durationSince($startedAt);
            $failure = $this->failureForConnectionException($exception);
            $this->lifecycle->markFailed($relay, $failure, [], $duration);

            throw $exception;
        } catch (TooManyRedirectsException $exception) {
            $duration = $this->durationSince($startedAt);
            $this->lifecycle->markFailed($relay, RelayFailure::TOO_MANY_REDIRECTS, [], $duration);

            throw new RelayHttpException(
                'Redirect limit exceeded for relay HTTP delivery.',
                RelayFailure::TOO_MANY_REDIRECTS,
                0,
                $exception
            );
        } catch (RequestException $exception) {
            $duration = $this->durationSince($startedAt);
            $this->lifecycle->markFailed($relay, RelayFailure::OUTBOUND_HTTP_ERROR, [], $duration);

            throw $exception;
        }

        $duration = $this->durationSince($startedAt);

        $this->evaluateRedirects($url, $response, $relay, $duration);

        $payload = $this->normalizePayload($response);

        $this->lifecycle->recordResponse($relay, $response->status(), $payload);

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

        throw new RelayHttpException(
            'Atlas Relay HTTP deliveries require HTTPS targets.',
            RelayFailure::OUTBOUND_HTTP_ERROR
        );
    }

    private function evaluateRedirects(string $originalUrl, Response $response, Relay $relay, int $duration): void
    {
        $stats = $response->handlerStats();
        $redirectCount = (int) ($stats['redirect_count'] ?? 0);
        $maxRedirects = (int) config('atlas-relay.http.max_redirects', 3);

        if ($redirectCount > $maxRedirects) {
            $this->lifecycle->markFailed($relay, RelayFailure::TOO_MANY_REDIRECTS, [], $duration);

            throw new RelayHttpException(
                'Redirect limit exceeded for relay HTTP delivery.',
                RelayFailure::TOO_MANY_REDIRECTS
            );
        }

        $effective = (string) ($response->effectiveUri() ?? $originalUrl);

        $originalHost = parse_url($originalUrl, PHP_URL_HOST);
        $effectiveHost = parse_url($effective, PHP_URL_HOST);

        if (is_string($effectiveHost) && is_string($originalHost) && ! hash_equals($originalHost, $effectiveHost)) {
            $this->lifecycle->markFailed($relay, RelayFailure::REDIRECT_HOST_CHANGED, [], $duration);

            throw new RelayHttpException(
                'Redirect attempted to a different host.',
                RelayFailure::REDIRECT_HOST_CHANGED
            );
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

    private function normalizePayload(Response $response): mixed
    {
        $json = $response->json();

        if (is_array($json)) {
            return $json;
        }

        return $this->truncatePayload($response->body());
    }

    private function applyRedirectGuards(string $originalUrl, Relay $relay, float $startedAt): void
    {
        $originalHost = parse_url($originalUrl, PHP_URL_HOST);
        $maxRedirects = (int) config('atlas-relay.http.max_redirects', 3);

        $this->pendingRequest = $this->pendingRequest->withOptions([
            'allow_redirects' => [
                'max' => $maxRedirects,
                'strict' => true,
                'referer' => false,
                'track_redirects' => true,
                'on_redirect' => function (
                    RequestInterface $request,
                    ResponseInterface $response,
                    UriInterface $uri
                ) use ($relay, $startedAt, $originalHost) {
                    $targetHost = $uri->getHost();

                    if (! is_string($originalHost) || $originalHost === '' || $targetHost === '') {
                        return;
                    }

                    if (! hash_equals($originalHost, $targetHost)) {
                        $duration = $this->durationSince($startedAt);
                        $this->lifecycle->markFailed(
                            $relay,
                            RelayFailure::REDIRECT_HOST_CHANGED,
                            [],
                            $duration
                        );

                        throw new RelayHttpException(
                            'Redirect attempted to a different host.',
                            RelayFailure::REDIRECT_HOST_CHANGED
                        );
                    }
                },
            ],
        ]);
    }
}
