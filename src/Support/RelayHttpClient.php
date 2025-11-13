<?php

declare(strict_types=1);

namespace Atlas\Relay\Support;

use Atlas\Relay\Enums\DestinationMethod;
use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Exceptions\RelayHttpException;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Services\RelayLifecycleService;
use Closure;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonSerializable;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Traversable;

/**
 * Proxy around Laravel's HTTP client that enforces PRD rules and records relay lifecycle data.
 *
 * @method Response get(string $url, mixed ...$arguments)
 * @method Response post(string $url, mixed ...$arguments)
 * @method Response put(string $url, mixed ...$arguments)
 * @method Response patch(string $url, mixed ...$arguments)
 * @method Response delete(string $url, mixed ...$arguments)
 * @method Response head(string $url, mixed ...$arguments)
 *
 * @mixin PendingRequest
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
        private readonly Closure $relayResolver,
        private readonly ?Closure $headerRecorder = null
    ) {}

    private ?Relay $resolvedRelay = null;

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $normalized = strtolower($method);

        if (in_array($normalized, $this->verbs, true)) {
            return $this->send($normalized, ...$arguments);
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
        $destinationMethod = DestinationMethod::tryFromMixed($method);

        $this->recordPendingHeaders();
        $relay = $this->relay();

        try {
            if (! is_string($url)) {
                throw new RelayHttpException(
                    'HTTP relay calls require a target URL.',
                    RelayFailure::OUTBOUND_HTTP_ERROR
                );
            }

            if ($destinationMethod === null) {
                $this->reportInvalidMethod($relay, $method);

                throw new RelayHttpException(
                    sprintf('Unsupported HTTP method [%s] for relay delivery.', $method),
                    RelayFailure::OUTBOUND_HTTP_ERROR
                );
            }

            $this->assertHttps($url);
            $this->registerPayloadFromArguments($relay, $arguments);
            $this->registerDestination($relay, $url, $destinationMethod);
        } catch (RelayHttpException $exception) {
            $failure = $exception->failure() ?? RelayFailure::OUTBOUND_HTTP_ERROR;

            $this->lifecycle->markFailed($relay, $failure);
            $this->lifecycle->recordResponse($relay, null, $exception->getMessage());

            throw $exception;
        }

        $relay = $this->lifecycle->startAttempt($relay);
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

    private function relay(): Relay
    {
        if ($this->resolvedRelay instanceof Relay) {
            return $this->resolvedRelay;
        }

        $resolver = $this->relayResolver;
        $relay = $resolver();

        if (! $relay instanceof Relay) {
            throw new RuntimeException('Relay resolver must return a Relay instance.');
        }

        $this->resolvedRelay = $relay;

        return $this->resolvedRelay;
    }

    private function recordPendingHeaders(): void
    {
        if ($this->headerRecorder === null) {
            return;
        }

        $headers = $this->pendingRequest->getOptions()['headers'] ?? [];

        if (! is_array($headers) || $headers === []) {
            return;
        }

        $recorder = $this->headerRecorder;
        $recorder($headers);
    }

    private function failureForConnectionException(ConnectionException $exception): RelayFailure
    {
        return Str::contains(strtolower($exception->getMessage()), 'timed out')
            ? RelayFailure::CONNECTION_TIMEOUT
            : RelayFailure::CONNECTION_ERROR;
    }

    private function reportInvalidMethod(Relay $relay, string $method): void
    {
        Log::warning('atlas-relay:http-method-invalid', [
            'relay_id' => $relay->id,
            'method' => $method,
            'allowed' => DestinationMethod::values(),
        ]);
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

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function registerPayloadFromArguments(Relay $relay, array $arguments): void
    {
        if ($relay->payload !== null) {
            return;
        }

        $payload = $arguments[1] ?? null;

        if ($payload === null) {
            return;
        }

        $normalized = $this->normalizeOutgoingPayload($payload);

        if ($normalized === null) {
            return;
        }

        $maxBytes = (int) config('atlas-relay.capture.max_payload_bytes', 64 * 1024);
        $payloadBytes = $this->payloadSize($normalized);

        if ($payloadBytes > $maxBytes) {
            throw new RelayHttpException(
                sprintf('Payload exceeds configured limit of %d bytes.', $maxBytes),
                RelayFailure::PAYLOAD_TOO_LARGE
            );
        }

        $relay->forceFill(['payload' => $normalized])->save();
    }

    private function normalizeOutgoingPayload(mixed $payload): mixed
    {
        if ($payload instanceof Arrayable) {
            return $payload->toArray();
        }

        if ($payload instanceof JsonSerializable) {
            $payload = $payload->jsonSerialize();
        } elseif ($payload instanceof Traversable) {
            $payload = iterator_to_array($payload);
        } elseif (is_object($payload) && method_exists($payload, 'toArray')) {
            $converted = $payload->toArray();

            if (is_array($converted)) {
                $payload = $converted;
            }
        }

        if (is_array($payload)) {
            return $payload;
        }

        if ($payload instanceof \stdClass) {
            return (array) $payload;
        }

        if (is_scalar($payload) || $payload === null) {
            return $payload;
        }

        if (is_object($payload) && method_exists($payload, '__toString')) {
            return (string) $payload;
        }

        return null;
    }

    private function payloadSize(mixed $payload): int
    {
        if ($payload === null) {
            return 0;
        }

        if (is_string($payload)) {
            return strlen($payload);
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return 0;
        }

        return strlen($encoded);
    }

    private function registerDestination(Relay $relay, string $url, DestinationMethod $method): void
    {
        $maxLength = 255;

        if (strlen($url) > $maxLength) {
            throw new RelayHttpException(
                sprintf('Destination URL may not exceed %d characters; received %d.', $maxLength, strlen($url)),
                RelayFailure::OUTBOUND_HTTP_ERROR
            );
        }

        $attributes = [];

        if ($relay->destination_url !== $url) {
            $attributes['destination_url'] = $url;
        }

        if ($relay->destination_method?->value !== $method->value) {
            $attributes['destination_method'] = $method;
        }

        if ($attributes === []) {
            return;
        }

        $relay->forceFill($attributes)->save();
    }
}
