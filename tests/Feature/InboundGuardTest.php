<?php

declare(strict_types=1);

namespace Atlas\Relay\Tests\Feature;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Exceptions\InvalidWebhookHeadersException;
use Atlas\Relay\Exceptions\InvalidWebhookPayloadException;
use Atlas\Relay\Facades\Relay;
use Atlas\Relay\Models\Relay as RelayModel;
use Atlas\Relay\Tests\Support\FakeInboundRequestGuard;
use Atlas\Relay\Tests\TestCase;
use Illuminate\Http\Request as HttpRequest;

/**
 * Validates inbound guard behavior for class-based validators per PRD: Receive Webhook Relay — Guard Validation.
 */
class InboundGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeInboundRequestGuard::reset();
    }

    public function test_guard_records_header_failure_when_capture_enabled(): void
    {
        FakeInboundRequestGuard::$mode = FakeInboundRequestGuard::MODE_HEADERS;
        FakeInboundRequestGuard::$captureFailures = true;

        $request = HttpRequest::create('/relay', 'POST');

        try {
            Relay::request($request)
                ->guard(FakeInboundRequestGuard::class)
                ->capture();

            $this->fail('Header guard exception was not thrown.');
        } catch (InvalidWebhookHeadersException $exception) {
            $this->assertStringContainsString('FakeInboundRequestGuard', $exception->getMessage());
        }

        $relay = RelayModel::query()->first();

        $this->assertInstanceOf(RelayModel::class, $relay);
        $this->assertSame(RelayStatus::FAILED, $relay->status);
        $this->assertSame(RelayFailure::INVALID_GUARD_HEADERS->value, $relay->failure_reason);
        $this->assertSame(403, $relay->response_http_status);
        $this->assertIsArray($relay->response_payload);
        $this->assertSame('FakeInboundRequestGuard', $relay->response_payload['guard'] ?? null);
        $this->assertNotEmpty($relay->response_payload['violations'] ?? []);
        $this->assertSame($relay->id, FakeInboundRequestGuard::$captures[0]['relay_id']);
    }

    public function test_capture_skipped_when_guard_capture_disabled(): void
    {
        FakeInboundRequestGuard::$mode = FakeInboundRequestGuard::MODE_HEADERS;
        FakeInboundRequestGuard::$captureFailures = false;

        $request = HttpRequest::create('/relay', 'POST');

        try {
            Relay::request($request)
                ->guard(FakeInboundRequestGuard::class)
                ->capture();

            $this->fail('Header guard exception was not thrown.');
        } catch (InvalidWebhookHeadersException $exception) {
            $this->assertStringContainsString('FakeInboundRequestGuard', $exception->getMessage());
        }

        $this->assertSame(0, RelayModel::query()->count());
        $this->assertNull(FakeInboundRequestGuard::$captures[0]['relay_id']);
    }

    public function test_guard_can_raise_payload_errors(): void
    {
        FakeInboundRequestGuard::$mode = FakeInboundRequestGuard::MODE_PAYLOAD;
        FakeInboundRequestGuard::$captureFailures = true;

        $request = HttpRequest::create('/relay', 'POST');

        try {
            Relay::request($request)
                ->guard(FakeInboundRequestGuard::class)
                ->capture();

            $this->fail('Payload guard exception was not thrown.');
        } catch (InvalidWebhookPayloadException $exception) {
            $this->assertStringContainsString('FakeInboundRequestGuard', $exception->getMessage());
        }

        $relay = RelayModel::query()->first();

        $this->assertInstanceOf(RelayModel::class, $relay);
        $this->assertSame(RelayFailure::INVALID_GUARD_PAYLOAD->value, $relay->failure_reason);
        $this->assertSame(422, $relay->response_http_status);
        $this->assertSame('FakeInboundRequestGuard', $relay->response_payload['guard'] ?? null);
        $this->assertNotEmpty($relay->response_payload['errors'] ?? []);
    }

    public function test_guard_allows_request_and_captures_when_valid(): void
    {
        FakeInboundRequestGuard::$expectedSignature = 'valid';
        FakeInboundRequestGuard::$mode = FakeInboundRequestGuard::MODE_NONE;

        $request = HttpRequest::create('/relay', 'POST');
        $request->headers->set('Stripe-Signature', 'valid');

        $relay = Relay::request($request)
            ->guard(FakeInboundRequestGuard::class)
            ->capture();

        $this->assertInstanceOf(RelayModel::class, $relay);
        $this->assertSame(RelayStatus::QUEUED, $relay->status);
        $this->assertNull($relay->failure_reason);
        $this->assertCount(2, FakeInboundRequestGuard::$captures);
        $this->assertSame('payload', FakeInboundRequestGuard::$captures[1]['phase']);
    }
}
