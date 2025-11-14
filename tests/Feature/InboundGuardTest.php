<?php

declare(strict_types=1);

namespace Atlas\Relay\Tests\Feature;

use Atlas\Relay\Enums\RelayFailure;
use Atlas\Relay\Enums\RelayStatus;
use Atlas\Relay\Exceptions\ForbiddenWebhookException;
use Atlas\Relay\Facades\Relay;
use Atlas\Relay\Models\Relay as RelayModel;
use Atlas\Relay\Tests\Support\FakeGuardValidator;
use Atlas\Relay\Tests\TestCase;
use Illuminate\Http\Request as HttpRequest;

/**
 * Validates inbound guard behavior for provider mapping, capture toggles, and custom validators per PRD: Inbound Guards — Authentication Gate.
 */
class InboundGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeGuardValidator::reset();
    }

    public function test_guard_records_forbidden_attempt_when_capture_enabled(): void
    {
        config()->set('atlas-relay.inbound', [
            'provider_guards' => [
                'stripe' => 'stripe-signature',
            ],
            'guards' => [
                'stripe-signature' => [
                    'capture_forbidden' => true,
                    'required_headers' => [
                        'Stripe-Signature',
                    ],
                ],
            ],
        ]);

        $request = HttpRequest::create('/relay', 'POST');

        try {
            Relay::request($request)
                ->provider('stripe')
                ->capture();

            $this->fail('Forbidden guard exception was not thrown.');
        } catch (ForbiddenWebhookException $exception) {
            $this->assertStringContainsString('Inbound guard [stripe-signature] rejected the request', $exception->getMessage());
        }

        $relay = RelayModel::query()->first();

        $this->assertInstanceOf(RelayModel::class, $relay);
        $this->assertSame('stripe', $relay->provider);
        $this->assertSame(RelayStatus::FAILED, $relay->status);
        $this->assertSame(RelayFailure::FORBIDDEN_GUARD->value, $relay->failure_reason);
        $this->assertSame(403, $relay->response_http_status);
        $this->assertIsArray($relay->response_payload);
        $this->assertSame('stripe-signature', $relay->response_payload['guard'] ?? null);
        $this->assertNotEmpty($relay->response_payload['violations'] ?? []);
    }

    public function test_capture_skipped_when_guard_capture_disabled(): void
    {
        config()->set('atlas-relay.inbound', [
            'provider_guards' => [
                'internal' => 'local-dev',
            ],
            'guards' => [
                'local-dev' => [
                    'capture_forbidden' => false,
                    'required_headers' => [
                        'X-Test-Header',
                    ],
                ],
            ],
        ]);

        $request = HttpRequest::create('/relay', 'POST');

        try {
            Relay::request($request)
                ->provider('internal')
                ->capture();

            $this->fail('Forbidden guard exception was not thrown.');
        } catch (ForbiddenWebhookException $exception) {
            $this->assertStringContainsString('local-dev', $exception->getMessage());
        }

        $this->assertSame(0, RelayModel::query()->count());
    }

    public function test_custom_guard_validator_can_reject_request(): void
    {
        config()->set('atlas-relay.inbound', [
            'provider_guards' => [],
            'guards' => [
                'validator-guard' => [
                    'capture_forbidden' => true,
                    'required_headers' => [
                        'X-Signature' => 'expected',
                    ],
                    'validator' => FakeGuardValidator::class,
                ],
            ],
        ]);

        FakeGuardValidator::$shouldFail = true;

        $request = HttpRequest::create('/relay', 'POST');
        $request->headers->set('X-Signature', 'expected');

        try {
            Relay::request($request)
                ->guard('validator-guard')
                ->capture();

            $this->fail('Validator guard exception was not thrown.');
        } catch (ForbiddenWebhookException $exception) {
            $this->assertStringContainsString('validator-guard', $exception->getMessage());
        }

        $relay = RelayModel::query()->first();

        $this->assertInstanceOf(RelayModel::class, $relay);
        $this->assertSame(RelayFailure::FORBIDDEN_GUARD->value, $relay->failure_reason);
        $this->assertNotEmpty(FakeGuardValidator::$captures);
        $this->assertSame($relay->id, FakeGuardValidator::$captures[0]['relay_id']);
    }

    public function test_guard_allows_request_and_captures_when_valid(): void
    {
        config()->set('atlas-relay.inbound', [
            'provider_guards' => [
                'stripe' => 'stripe-signature',
            ],
            'guards' => [
                'stripe-signature' => [
                    'capture_forbidden' => false,
                    'required_headers' => [
                        'Stripe-Signature' => 'valid',
                    ],
                ],
            ],
        ]);

        $request = HttpRequest::create('/relay', 'POST');
        $request->headers->set('Stripe-Signature', 'valid');

        $relay = Relay::request($request)
            ->provider('stripe')
            ->capture();

        $this->assertInstanceOf(RelayModel::class, $relay);
        $this->assertSame(RelayStatus::QUEUED, $relay->status);
        $this->assertNull($relay->failure_reason);
    }
}
