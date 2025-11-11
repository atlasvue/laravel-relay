<?php

declare(strict_types=1);

namespace AtlasRelay\Tests\Feature;

use AtlasRelay\Enums\RelayFailure;
use AtlasRelay\Facades\Relay;
use AtlasRelay\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpDeliveryTest extends TestCase
{
    public function test_http_delivery_records_response_and_completion(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response(['ok' => true], 200),
        ]);

        $builder = Relay::payload(['status' => 'queued']);

        $response = $builder->http()->post('https://example.com/relay', ['payload' => true]);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://example.com/relay';
        });

        $this->assertTrue($response->successful());

        $relay = $builder->relay();
        $this->assertSame('completed', $relay?->status);
        $this->assertSame(200, $relay?->response_status);
        $this->assertSame(['ok' => true], $relay?->response_payload);
    }

    public function test_http_failure_records_failure_reason(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response('nope', 500),
        ]);

        $builder = Relay::payload(['status' => 'queued']);

        $response = $builder->http()->post('https://example.com/fail');

        $this->assertFalse($response->successful());

        $relay = $builder->relay();
        $this->assertSame('failed', $relay?->status);
        $this->assertSame(RelayFailure::OUTBOUND_HTTP_ERROR->value, $relay?->failure_reason);
    }

    public function test_http_requires_https_targets(): void
    {
        $builder = Relay::payload(['status' => 'queued']);

        $this->expectException(RuntimeException::class);

        $builder->http()->post('http://insecure.test');
    }
}
