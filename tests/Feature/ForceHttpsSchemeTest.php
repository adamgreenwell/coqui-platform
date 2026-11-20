<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cloudflare reaches the origin over HTTP and Traefik forwards
 * `X-Forwarded-Proto: http`, so redirects pointed at http:// and browsers
 * blocked them inside Inertia requests as mixed content.
 */
class ForceHttpsSchemeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const PROXIED_OVER_HTTP = [
        'HTTP_X_FORWARDED_PROTO' => 'http',
        'HTTP_X_FORWARDED_PORT' => '80',
        'HTTP_HOST' => 'coquiseo.com',
    ];

    public function test_redirects_use_https_behind_a_proxy_that_forwards_http(): void
    {
        config()->set('app.force_https', true);

        $this->withServerVariables(self::PROXIED_OVER_HTTP)
            ->get('http://coquiseo.com/dashboard')
            ->assertRedirect('https://coquiseo.com/login');
    }

    public function test_redirect_back_after_a_failed_form_uses_https(): void
    {
        config()->set('app.force_https', true);

        // Validation errors redirect to the previous URL, which the session
        // records from the incoming request.
        $this->withServerVariables(self::PROXIED_OVER_HTTP)
            ->get('http://coquiseo.com/login')
            ->assertOk();

        $this->withServerVariables(self::PROXIED_OVER_HTTP)
            ->post('http://coquiseo.com/login', [])
            ->assertRedirect('https://coquiseo.com/login');
    }

    public function test_generated_urls_use_https(): void
    {
        config()->set('app.force_https', true);
        config()->set('services.stripe.secret', null);
        config()->set('services.stripe.prices', ['pro' => null, 'agency' => null, 'runnr_addon' => null]);

        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Https Workspace',
            'slug' => 'https-workspace',
            'owner_id' => $owner->id,
            'plan_tier' => 'free',
        ]);
        $workspace->members()->attach($owner->id, ['role' => 'owner']);

        // Billing builds its Stripe return URLs and redirects with route().
        $location = (string) $this->actingAs($owner)
            ->withServerVariables(self::PROXIED_OVER_HTTP)
            ->post('http://coquiseo.com'.route('workspaces.billing.portal', $workspace, absolute: false))
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith(
            'https://coquiseo.com'.route('workspaces.billing.index', $workspace, absolute: false),
            $location,
        );
    }

    public function test_the_forwarded_scheme_is_left_alone_when_https_is_not_forced(): void
    {
        config()->set('app.force_https', false);

        $this->withServerVariables(self::PROXIED_OVER_HTTP)
            ->get('http://coquiseo.com/dashboard')
            ->assertRedirect('http://coquiseo.com/login');
    }
}
