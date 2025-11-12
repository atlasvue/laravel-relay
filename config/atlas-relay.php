<?php

declare(strict_types=1);

return [
    'tables' => [
        'relays' => 'atlas_relays',
        'relay_routes' => 'atlas_relay_routes',
        'relay_archives' => 'atlas_relay_archives',
    ],

    'database' => [
        'connection' => env('ATLAS_RELAY_DATABASE_CONNECTION'),
    ],

    'archiving' => [
        'archive_after_days' => env('ATLAS_RELAY_ARCHIVE_DAYS', 30),
        'purge_after_days' => env('ATLAS_RELAY_PURGE_DAYS', 180),
        'chunk_size' => env('ATLAS_RELAY_ARCHIVE_CHUNK_SIZE', 500),
    ],

    'capture' => [
        'max_payload_bytes' => env('ATLAS_RELAY_MAX_PAYLOAD_BYTES', 64 * 1024),
        'sensitive_headers' => [
            'authorization',
            'proxy-authorization',
            'x-api-key',
            'api-key',
            'cookie',
        ],
        'header_whitelist' => [],
        'masked_value' => env('ATLAS_RELAY_SENSITIVE_HEADER_MASK', '***'),
    ],

    'lifecycle' => [
        'default_retry_seconds' => env('ATLAS_RELAY_DEFAULT_RETRY_SECONDS', 60),
        'default_retry_max_attempts' => env('ATLAS_RELAY_DEFAULT_RETRY_MAX_ATTEMPTS', 3),
        'default_delay_seconds' => env('ATLAS_RELAY_DEFAULT_DELAY_SECONDS', 0),
        'default_timeout_seconds' => env('ATLAS_RELAY_DEFAULT_TIMEOUT_SECONDS', 30),
        'default_http_timeout_seconds' => env('ATLAS_RELAY_DEFAULT_HTTP_TIMEOUT_SECONDS', 30),
        'exception_response_max_bytes' => env('ATLAS_RELAY_EXCEPTION_RESPONSE_MAX_BYTES', 1024),
    ],

    'routing' => [
        'cache_ttl_seconds' => env('ATLAS_RELAY_ROUTE_CACHE_SECONDS', 1200),
        'cache_store' => env('ATLAS_RELAY_ROUTE_CACHE_STORE'),
    ],

    'http' => [
        'max_response_bytes' => env('ATLAS_RELAY_MAX_RESPONSE_BYTES', 16 * 1024),
        'max_redirects' => env('ATLAS_RELAY_MAX_REDIRECTS', 3),
        'enforce_https' => env('ATLAS_RELAY_ENFORCE_HTTPS', true),
    ],

    'automation' => [
        'retry_overdue_cron' => env('ATLAS_RELAY_RETRY_CRON', '*/1 * * * *'),
        'stuck_requeue_cron' => env('ATLAS_RELAY_STUCK_CRON', '*/10 * * * *'),
        'timeout_enforcement_cron' => env('ATLAS_RELAY_TIMEOUT_CRON', '0 * * * *'),
        'archive_cron' => env('ATLAS_RELAY_ARCHIVE_CRON', '0 22 * * *'),
        'purge_cron' => env('ATLAS_RELAY_PURGE_CRON', '0 23 * * *'),
        'stuck_threshold_minutes' => env('ATLAS_RELAY_STUCK_THRESHOLD_MINUTES', 10),
        'timeout_buffer_seconds' => env('ATLAS_RELAY_TIMEOUT_BUFFER_SECONDS', 0),
    ],
];
