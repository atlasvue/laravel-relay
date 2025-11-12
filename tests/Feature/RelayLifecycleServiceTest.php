<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Contracts\RelayManagerInterface;
use AtlasRelay\Enums\RelayStatus;
use AtlasRelay\Facades\Relay;
use AtlasRelay\Tests\TestCase;

/**
 * Ensures the lifecycle service can cancel relays and replay them back into the queue while clearing failure state.
 *
 * Defined by PRD: Atlas Relay — Lifecycle Flow Summary and Notes on retries and replays.
 */
class RelayLifecycleServiceTest extends TestCase
{
    public function test_cancel_and_replay_flow(): void
    {
        $relay = Relay::payload(['foo' => 'bar'])->capture();

        /** @var RelayManagerInterface $manager */
        $manager = app(RelayManagerInterface::class);

        $cancelled = $manager->cancel($relay);
        $this->assertSame(RelayStatus::CANCELLED, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);

        $replayed = $manager->replay($cancelled);
        $this->assertSame(RelayStatus::QUEUED, $replayed->status);
        $this->assertNull($replayed->failure_reason);
        $this->assertNull($replayed->cancelled_at);
        $this->assertSame(0, $replayed->attempt_count);
    }
}
