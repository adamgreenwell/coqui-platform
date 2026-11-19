<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Services\StripeWebhookSignature;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StripeWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_signature';

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('pennant.default', 'array');
        config()->set('services.stripe.webhook_secret', self::SECRET);

        $owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Signature Workspace',
            'slug' => 'signature-workspace',
            'owner_id' => $owner->id,
            'plan_tier' => 'free',
        ]);
    }

    public function test_valid_signature_is_processed(): void
    {
        $body = $this->upgradePayload();

        $this->postRaw($body, StripeWebhookSignature::sign($body, self::SECRET))->assertOk();

        $this->assertSame('agency', $this->workspace->refresh()->plan_tier);
    }

    public function test_missing_signature_is_rejected_without_changes(): void
    {
        $this->postRaw($this->upgradePayload(), null)->assertStatus(400);

        $this->assertSame('free', $this->workspace->refresh()->plan_tier);
    }

    public function test_signature_with_wrong_secret_is_rejected(): void
    {
        $body = $this->upgradePayload();

        $this->postRaw($body, StripeWebhookSignature::sign($body, 'whsec_attacker'))->assertStatus(400);

        $this->assertSame('free', $this->workspace->refresh()->plan_tier);
    }

    public function test_signature_for_a_different_body_is_rejected(): void
    {
        $signedBody = (string) json_encode(['type' => 'ping']);

        $this->postRaw($this->upgradePayload(), StripeWebhookSignature::sign($signedBody, self::SECRET))
            ->assertStatus(400);

        $this->assertSame('free', $this->workspace->refresh()->plan_tier);
    }

    public function test_stale_signature_is_rejected(): void
    {
        $body = $this->upgradePayload();
        $header = StripeWebhookSignature::sign($body, self::SECRET, time() - 3600);

        $this->postRaw($body, $header)->assertStatus(400);

        $this->assertSame('free', $this->workspace->refresh()->plan_tier);
    }

    public function test_webhooks_fail_closed_when_secret_is_not_configured(): void
    {
        config()->set('services.stripe.webhook_secret', '');

        $this->postRaw($this->upgradePayload(), 't=1,v1=deadbeef')->assertStatus(503);

        $this->assertSame('free', $this->workspace->refresh()->plan_tier);
    }

    public function test_header_parser_accepts_any_matching_v1_signature(): void
    {
        $body = '{"type":"ping"}';
        $valid = StripeWebhookSignature::sign($body, self::SECRET, 1_700_000_000);
        $header = 't=1700000000,v1=0000,'.explode(',', $valid)[1];

        $this->assertTrue(StripeWebhookSignature::isValid($body, $header, self::SECRET, 300, 1_700_000_010));
        $this->assertFalse(StripeWebhookSignature::isValid($body, 't=1700000000', self::SECRET, 300, 1_700_000_010));
        $this->assertFalse(StripeWebhookSignature::isValid($body, 'garbage', self::SECRET, 300, 1_700_000_010));
    }

    private function upgradePayload(): string
    {
        return (string) json_encode([
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_attack',
                    'customer' => 'cus_attack',
                    'subscription' => 'sub_attack',
                    'metadata' => [
                        'workspace_id' => (string) $this->workspace->id,
                        'plan_tier' => 'agency',
                    ],
                ],
            ],
        ]);
    }

    private function postRaw(string $body, ?string $signature): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_STRIPE_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], $server, $body);
    }
}
