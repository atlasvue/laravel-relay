<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Facades\Relay;
use AtlasRelay\Models\Relay as RelayModel;
use AtlasRelay\Tests\TestCase;
use Illuminate\Http\Request;

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
            ->meta(['source' => 'test'])
            ->capture();

        $this->assertInstanceOf(RelayModel::class, $relay);
        $this->assertSame('queued', $relay->status);
        $this->assertSame('event', $relay->mode);
        $this->assertSame('127.0.0.1', $relay->request_source);
        $this->assertSame(['status' => 'queued'], $relay->payload);
        $this->assertSame('***', $relay->headers['authorization']);
        $this->assertSame('Value', $relay->headers['x-custom']);
        $this->assertTrue($relay->is_retry);
        $this->assertSame(120, $relay->retry_seconds);
        $this->assertSame(5, $relay->retry_max_attempts);
        $this->assertTrue($relay->is_delay);
        $this->assertSame(10, $relay->delay_seconds);
        $this->assertSame(45, $relay->timeout_seconds);
        $this->assertSame(30, $relay->http_timeout_seconds);
        $this->assertSame(['source' => 'test'], $relay->meta);
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

        $this->assertSame('12345', $relay->headers['x-secret-token']);

        config()->set('atlas-relay.capture.sensitive_headers', $originalSensitive);
        config()->set('atlas-relay.capture.header_whitelist', $originalWhitelist);
    }

    public function test_payload_size_limit_marks_relay_failed(): void
    {
        $payload = ['data' => str_repeat('A', 70 * 1024)];

        $relay = Relay::payload($payload)->capture();

        $this->assertSame('failed', $relay->status);
        $this->assertSame(RelayFailure::PAYLOAD_TOO_LARGE->value, $relay->failure_reason);
        $this->assertNull($relay->payload);
        $this->assertArrayHasKey('validation_errors', $relay->meta);
        $this->assertSame(
            'Payload exceeds configured limit of 65536 bytes.',
            $relay->meta['validation_errors']['payload'][0]
        );
    }

    public function test_validation_errors_are_persisted_with_failure_reason(): void
    {
        $relay = Relay::payload(['foo' => 'bar'])
            ->validationError('payload', 'Invalid structure.')
            ->failWith(RelayFailure::INVALID_PAYLOAD)
            ->capture();

        $this->assertSame('failed', $relay->status);
        $this->assertSame(RelayFailure::INVALID_PAYLOAD->value, $relay->failure_reason);
        $this->assertSame('Invalid structure.', $relay->meta['validation_errors']['payload'][0]);
    }
}
