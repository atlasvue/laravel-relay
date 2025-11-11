<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Contracts\RelayManagerInterface;
use AtlasRelay\Facades\Relay;
use AtlasRelay\Tests\TestCase;

class RelayLifecycleServiceTest extends TestCase
{
    public function test_cancel_and_replay_flow(): void
    {
        $relay = Relay::payload(['foo' => 'bar'])->capture();

        /** @var RelayManagerInterface $manager */
        $manager = $this->app->make(RelayManagerInterface::class);

        $cancelled = $manager->cancel($relay);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);

        $replayed = $manager->replay($cancelled);
        $this->assertSame('queued', $replayed->status);
        $this->assertNull($replayed->failure_reason);
        $this->assertNull($replayed->cancelled_at);
        $this->assertSame(0, $replayed->attempt_count);
    }
}
