<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Facades\Relay;
use AtlasRelay\Tests\TestCase;
use Illuminate\Http\Request;

/**
 * Verifies the Relay facade builders retain payload state and capture incoming requests for downstream lifecycle operations.
 *
 * Defined by PRD: Atlas Relay — Core API Patterns.
 */
class RelayFacadeTest extends TestCase
{
    public function test_payload_builder_retains_payload_state(): void
    {
        $builder = Relay::payload(['status' => 'queued']);
        $context = $builder->context();

        $this->assertSame(['status' => 'queued'], $context->payload);
        $this->assertNull($context->request);
    }

    public function test_request_builder_captures_request_instance(): void
    {
        $request = Request::create('/relay', 'POST', ['hello' => 'world']);
        $builder = Relay::request($request);
        $context = $builder->context();

        $this->assertSame($request, $context->request);
    }
}
