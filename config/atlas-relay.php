<?php

declare(strict_types=1);

return [
    'tables' => [
        'relays' => 'atlas_relays',
        'relay_routes' => 'atlas_relay_routes',
        'relay_archives' => 'atlas_relay_archives',
    ],

    'archiving' => [
        'archive_after_days' => env('ATLAS_RELAY_ARCHIVE_DAYS', 30),
        'purge_after_days' => env('ATLAS_RELAY_PURGE_DAYS', 180),
        'chunk_size' => env('ATLAS_RELAY_ARCHIVE_CHUNK_SIZE', 500),
    ],

    'routing' => [
        'cache_ttl_seconds' => env('ATLAS_RELAY_ROUTE_CACHE_SECONDS', 1200),
    ],
];
