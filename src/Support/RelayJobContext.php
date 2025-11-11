<?php

declare(strict_types=1);

namespace AtlasRelay\Support;

use AtlasRelay\Models\Relay;

/**
 * Stores per-job relay context so jobs can introspect or signal failures.
 */
class RelayJobContext
{
    private static ?Relay $current = null;

    public static function set(Relay $relay): void
    {
        static::$current = $relay;
    }

    public static function current(): ?Relay
    {
        return static::$current;
    }

    public static function clear(): void
    {
        static::$current = null;
    }
}
