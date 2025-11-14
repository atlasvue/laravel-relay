<?php

declare(strict_types=1);

namespace Atlas\Relay\Enums;

/**
 * Centralized relay failure codes defined across the Receive and Send Webhook Relay PRDs.
 */
enum RelayFailure: int
{
    case EXCEPTION = 100;
    case PAYLOAD_TOO_LARGE = 101;
    case NO_ROUTE_MATCH = 102;
    case CANCELLED = 103;
    case ROUTE_TIMEOUT = 104;
    case INVALID_PAYLOAD = 105;
    case ROUTE_DISABLED = 106;
    case ROUTE_RESOLVER_ERROR = 107;
    case FORBIDDEN_GUARD = 108;
    case HTTP_ERROR = 201;
    case TOO_MANY_REDIRECTS = 203;
    case REDIRECT_HOST_CHANGED = 204;
    case CONNECTION_ERROR = 205;
    case CONNECTION_TIMEOUT = 206;

    public function label(): string
    {
        return match ($this) {
            self::EXCEPTION => 'Exception',
            self::PAYLOAD_TOO_LARGE => 'Payload Too Large',
            self::NO_ROUTE_MATCH => 'No Route Match',
            self::CANCELLED => 'Cancelled',
            self::ROUTE_TIMEOUT => 'Route Timeout',
            self::INVALID_PAYLOAD => 'Invalid Payload',
            self::ROUTE_DISABLED => 'Route Disabled',
            self::ROUTE_RESOLVER_ERROR => 'Route Resolver Error',
            self::FORBIDDEN_GUARD => 'Forbidden Guard',
            self::HTTP_ERROR => 'HTTP Error',
            self::TOO_MANY_REDIRECTS => 'Too Many Redirects',
            self::REDIRECT_HOST_CHANGED => 'Redirect Host Changed',
            self::CONNECTION_ERROR => 'Connection Error',
            self::CONNECTION_TIMEOUT => 'Connection Timeout',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::EXCEPTION => 'Unhandled exception occurred during capture or delivery.',
            self::PAYLOAD_TOO_LARGE => 'Payload exceeds size limit (64KB) and is not retried.',
            self::NO_ROUTE_MATCH => 'No matching route found for inbound path/method.',
            self::CANCELLED => 'Relay manually cancelled before completion.',
            self::ROUTE_TIMEOUT => 'Exceeded configured routing timeout while awaiting execution.',
            self::INVALID_PAYLOAD => 'Payload body failed JSON decoding; raw request preserved.',
            self::ROUTE_DISABLED => 'Matched route is disabled and cannot be used.',
            self::ROUTE_RESOLVER_ERROR => 'Programmatic routing provider threw an exception.',
            self::FORBIDDEN_GUARD => 'Inbound provider guard rejected the request before processing.',
            self::HTTP_ERROR => 'Outbound response returned a non-2xx HTTP status code.',
            self::TOO_MANY_REDIRECTS => 'Redirect limit (3) exceeded during outbound request.',
            self::REDIRECT_HOST_CHANGED => 'Redirect attempted to a different host and was blocked.',
            self::CONNECTION_ERROR => 'Outbound delivery failed because of network, SSL, or DNS errors.',
            self::CONNECTION_TIMEOUT => 'Outbound delivery timed out before receiving a response.',
        };
    }
}
