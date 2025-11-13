<?php

declare(strict_types=1);

namespace Atlas\Relay\Tests\Feature;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Facades\Relay;
use Atlas\Relay\Models\Relay as RelayModel;
use Atlas\Relay\Tests\TestCase;
use Illuminate\Http\Request;

/**
 * Exercises payload capture flows covering header normalization, lifecycle overrides, payload limits, and validation failure handling.
 *
 * Defined by PRD: Payload Capture — Header Normalization, Payload Handling, Failure Reason Enum, and Edge Cases.
 */
class RelayCaptureTest extends TestCase
{
    public function test_capture_persists_normalized_headers_and_lifecycle_overrides(): void
    {
        $request = Request::create('/relay', 'POST', ['hello' => 'world']);
        $request->headers->set('Authorization', 'Bearer secret');
        $request->headers->set('X-CUSTOM', 'Value');

        $relay = Relay::request($request)
            ->payload(['status' => 'queued'])
            ->mode('event')
            ->retry(120, 5)
            ->delay(10)
            ->timeout(45)
            ->httpTimeout(30)
            ->capture();

        $this->assertInstanceOf(RelayModel::class, $relay);
        $this->assertSame(RelayStatus::QUEUED, $relay->status);
        $this->assertSame('event', $relay->mode);
        $this->assertSame('127.0.0.1', $relay->source);
        $this->assertSame(['status' => 'queued'], $relay->payload);
        $headers = $relay->headers ?? [];
        $this->assertSame('***', $headers['authorization'] ?? null);
        $this->assertSame('Value', $headers['x-custom'] ?? null);
        $this->assertTrue($relay->is_retry);
        $this->assertSame(120, $relay->retry_seconds);
        $this->assertSame(5, $relay->retry_max_attempts);
        $this->assertTrue($relay->is_delay);
        $this->assertSame(10, $relay->delay_seconds);
        $this->assertSame(45, $relay->timeout_seconds);
        $this->assertSame(30, $relay->http_timeout_seconds);
    }

    public function test_manual_headers_are_captured_when_request_missing(): void
    {
        $relay = Relay::payload(['status' => 'queued'])
            ->setHeaders([
                'X-API-KEY' => 'secret-key',
                'X-Trace' => 'relay-run',
            ])
            ->capture();

        $headers = $relay->headers ?? [];
        $this->assertSame('***', $headers['x-api-key'] ?? null);
        $this->assertSame('relay-run', $headers['x-trace'] ?? null);
    }

    public function test_whitelisted_headers_are_not_masked(): void
    {
        $originalSensitive = config('atlas-relay.capture.sensitive_headers');
        $originalWhitelist = config('atlas-relay.capture.header_whitelist');

        config()->set('atlas-relay.capture.sensitive_headers', ['x-secret-token']);
        config()->set('atlas-relay.capture.header_whitelist', ['X-Secret-Token']);

        $request = Request::create('/relay', 'POST');
        $request->headers->set('X-Secret-Token', '12345');

        $relay = Relay::request($request)->capture();

        $headers = $relay->headers ?? [];
        $this->assertSame('12345', $headers['x-secret-token'] ?? null);

        config()->set('atlas-relay.capture.sensitive_headers', $originalSensitive);
        config()->set('atlas-relay.capture.header_whitelist', $originalWhitelist);
    }

    public function test_payload_size_limit_marks_relay_failed(): void
    {
        $payload = ['data' => str_repeat('A', 70 * 1024)];

        $relay = Relay::payload($payload)->capture();

        $this->assertSame(RelayStatus::FAILED, $relay->status);
        $this->assertSame(RelayFailure::PAYLOAD_TOO_LARGE->value, $relay->failure_reason);
        $this->assertNull($relay->payload);
    }

    public function test_validation_errors_mark_failure_reason(): void
    {
        $relay = Relay::payload(['foo' => 'bar'])
            ->validationError('payload', 'Invalid structure.')
            ->failWith(RelayFailure::INVALID_PAYLOAD)
            ->capture();

        $this->assertSame(RelayStatus::FAILED, $relay->status);
        $this->assertSame(RelayFailure::INVALID_PAYLOAD->value, $relay->failure_reason);
    }

    public function test_malformed_json_payload_marks_capture_failed(): void
    {
        $request = Request::create('/relay', 'POST', [], [], [], [], '{"foo": "bar"');
        $request->headers->set('Content-Type', 'application/json');

        $relay = Relay::request($request)->capture();

        $this->assertSame(RelayStatus::FAILED, $relay->status);
        $this->assertSame(RelayFailure::INVALID_PAYLOAD->value, $relay->failure_reason);
        $this->assertSame('{"foo": "bar"', $relay->payload);
    }
}
