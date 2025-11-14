<?php

declare(strict_types=1);

namespace Atlas\Relay\Enums;

/**
 * Distinguishes relay records based on their lifecycle role.
 */
enum RelayType: int
{
    case INBOUND = 1;
    case OUTBOUND = 2;
    case RELAY = 3;

    public function label(): string
    {
        return match ($this) {
            self::INBOUND => 'Inbound',
            self::OUTBOUND => 'Outbound',
            self::RELAY => 'Relay',
        };
    }
}
