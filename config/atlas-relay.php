<?php

declare(strict_types=1);

return [
    'tables' => [
        'relays' => 'atlas_relays',
        'relay_archives' => 'atlas_relay_archives',
    ],

    'database' => [
        'connection' => env('ATLAS_RELAY_DATABASE_CONNECTION'),
    ],

    'archiving' => [
        'archive_after_days' => env('ATLAS_RELAY_ARCHIVE_DAYS', 30),
        'purge_after_days' => env('ATLAS_RELAY_PURGE_DAYS', 180),
    ],

    'payload_max_bytes' => 64 * 1024,

    'sensitive_headers' => [
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'api-key',
        'cookie',
    ],
];
