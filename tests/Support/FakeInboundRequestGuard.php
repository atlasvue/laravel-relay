<?php

declare(strict_types=1);

namespace Atlas\Relay\Tests\Support;

use Atlas\Relay\Exceptions\InvalidWebhookHeadersException;
use Atlas\Relay\Exceptions\InvalidWebhookPayloadException;
use Atlas\Relay\Guards\BaseInboundRequestGuard;
use Atlas\Relay\Support\InboundRequestGuardContext;

/**
 * Fake inbound guard used by feature tests per PRD: Receive Webhook Relay — Guard Validation.
 */
class FakeInboundRequestGuard extends BaseInboundRequestGuard
{
    public const MODE_NONE = 'none';

    public const MODE_HEADERS = 'headers';

    public const MODE_PAYLOAD = 'payload';

    public static string $mode = self::MODE_NONE;

    public static bool $captureFailures = true;

    public static ?string $expectedSignature = null;

    /** @var array<int, array{phase:string,relay_id:int|null}> */
    public static array $captures = [];

    public static function reset(): void
    {
        self::$mode = self::MODE_NONE;
        self::$captureFailures = true;
        self::$expectedSignature = null;
        self::$captures = [];
    }

    public function captureFailures(): bool
    {
        return self::$captureFailures;
    }

    public function validateHeaders(InboundRequestGuardContext $context): void
    {
        self::$captures[] = [
            'phase' => 'headers',
            'relay_id' => $context->relay()?->id,
        ];

        if (self::$expectedSignature !== null) {
            $value = $context->header('Stripe-Signature');

            if ($value !== self::$expectedSignature) {
                throw InvalidWebhookHeadersException::fromViolations($this->name(), ['signature mismatch']);
            }
        }

        if (self::$mode === self::MODE_HEADERS) {
            throw InvalidWebhookHeadersException::fromViolations($this->name(), ['blocked by header guard']);
        }
    }

    public function validatePayload(InboundRequestGuardContext $context): void
    {
        self::$captures[] = [
            'phase' => 'payload',
            'relay_id' => $context->relay()?->id,
        ];

        if (self::$mode === self::MODE_PAYLOAD) {
            throw InvalidWebhookPayloadException::fromViolations($this->name(), ['payload schema mismatch']);
        }
    }
}
