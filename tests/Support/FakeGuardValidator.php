<?php

declare(strict_types=1);

namespace Atlas\Relay\Tests\Support;

use Atlas\Relay\Contracts\InboundGuardValidatorInterface;
use Atlas\Relay\Exceptions\ForbiddenWebhookException;
use Atlas\Relay\Exceptions\InvalidWebhookPayloadException;
use Atlas\Relay\Models\Relay;
use Atlas\Relay\Support\InboundGuardProfile;
use Illuminate\Http\Request;

/**
 * Fake inbound guard validator used by feature tests per PRD: Inbound Guards — Authentication Gate.
 */
class FakeGuardValidator implements InboundGuardValidatorInterface
{
    public const MODE_NONE = 'none';

    public const MODE_FORBIDDEN = 'forbidden';

    public const MODE_PAYLOAD = 'payload';

    public static string $mode = self::MODE_NONE;

    /** @var array<int, array{relay_id:int|null}> */
    public static array $captures = [];

    public static function reset(): void
    {
        self::$mode = self::MODE_NONE;
        self::$captures = [];
    }

    public function validate(Request $request, InboundGuardProfile $profile, ?Relay $relay = null): void
    {
        self::$captures[] = [
            'relay_id' => $relay?->id,
        ];

        if (self::$mode === self::MODE_FORBIDDEN) {
            throw ForbiddenWebhookException::fromViolations($profile->name, ['signature mismatch']);
        }

        if (self::$mode === self::MODE_PAYLOAD) {
            throw InvalidWebhookPayloadException::fromViolations($profile->name, ['payload schema mismatch']);
        }
    }
}
