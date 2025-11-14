<?php

declare(strict_types=1);

namespace Atlas\Relay\Enums;

/**
 * Enumerates the lifecycle states assigned to relays per the Receive and Send Webhook Relay PRDs.
 */
enum RelayStatus: int
{
    case QUEUED = 0;
    case PROCESSING = 1;
    case COMPLETED = 2;
    case FAILED = 3;
    case CANCELLED = 4;

    public function label(): string
    {
        return match ($this) {
            self::QUEUED => 'queued',
            self::PROCESSING => 'processing',
            self::COMPLETED => 'completed',
            self::FAILED => 'failed',
            self::CANCELLED => 'cancelled',
        };
    }

    public static function fromLabel(string $label): self
    {
        return match (strtolower($label)) {
            'queued' => self::QUEUED,
            'processing' => self::PROCESSING,
            'completed' => self::COMPLETED,
            'failed' => self::FAILED,
            'cancelled' => self::CANCELLED,
            default => throw new \ValueError(sprintf('Unknown relay status label [%s].', $label)),
        };
    }
}
