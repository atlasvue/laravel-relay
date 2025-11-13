<?php

declare(strict_types=1);

namespace Atlas\Relay\Enums;

/**
 * Enumerates supported outbound HTTP verbs for relay deliveries.
 */
enum HttpMethod: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case HEAD = 'HEAD';
    case OPTIONS = 'OPTIONS';

    public static function tryFromMixed(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtoupper($value);

        return self::tryFrom($normalized);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $method): string => $method->value,
            self::cases()
        );
    }
}
