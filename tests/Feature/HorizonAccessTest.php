<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Horizon exposes every workspace's job payloads and can retry/delete jobs,
 * so outside APP_ENV=local only platform operators may open it (#100).
 */
class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_open_horizon(): void
    {
        $this->get('/horizon')->assertForbidden();
        $this->getJson('/horizon/api/stats')->assertForbidden();
    }

    public function test_regular_signed_in_users_cannot_open_horizon(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/horizon')->assertForbidden();
        $this->actingAs($user)->getJson('/horizon/api/stats')->assertForbidden();
    }

    public function test_workspace_owners_who_registered_normally_cannot_open_horizon(): void
    {
        $this->post('/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'is_operator' => true,
        ])->assertRedirect('/dashboard');

        $user = User::query()->where('email', 'mallory@example.com')->firstOrFail();
        $this->assertFalse($user->isOperator());

        $this->actingAs($user)->get('/horizon')->assertForbidden();
    }

    public function test_operators_can_open_horizon(): void
    {
        $operator = User::factory()->operator()->create();

        // The dashboard shell renders without Redis; its JSON API needs a live
        // Redis connection, so the API is only asserted for the 403 cases.
        $this->actingAs($operator)->get('/horizon')->assertOk();
    }

    public function test_operator_flag_is_not_mass_assignable(): void
    {
        $user = User::query()->create([
            'name' => 'Eve',
            'email' => 'eve@example.com',
            'password' => 'secret-password',
            'is_operator' => true,
        ]);

        $this->assertFalse($user->fresh()?->isOperator());
    }

    public function test_operator_command_grants_and_revokes_access(): void
    {
        $user = User::factory()->create(['email' => 'Ops@Example.com']);

        $this->artisan('coqui:operator', ['email' => 'ops@example.com'])
            ->expectsOutputToContain('is now a platform operator')
            ->assertSuccessful();
        $this->assertTrue($user->fresh()?->isOperator());

        $this->artisan('coqui:operator', ['email' => 'ops@example.com', '--revoke' => true])
            ->expectsOutputToContain('is no longer a platform operator')
            ->assertSuccessful();
        $this->assertFalse($user->fresh()?->isOperator());

        $this->artisan('coqui:operator', ['email' => 'nobody@example.com'])->assertFailed();
    }
}
