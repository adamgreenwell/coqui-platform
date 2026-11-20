<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Fortify's reset-password feature was enabled without any pages or reset
 * action, so the emailed link and /forgot-password both errored (#71).
 */
class PasswordResetAndProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_page_renders(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword'));
    }

    public function test_reset_link_is_emailed_to_a_known_user(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);

        $this->post('/forgot-password', ['email' => 'reset@example.com'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_page_renders_with_token_and_email(): void
    {
        $this->get('/reset-password/some-token?email=reset%40example.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ResetPassword')
                ->where('token', 'some-token')
                ->where('email', 'reset@example.com'));
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.com']);

        $this->post('/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'BrandNewPass123!',
            'password_confirmation' => 'BrandNewPass123!',
        ])
            ->assertRedirect('/login')
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check('BrandNewPass123!', $user->fresh()->password));
    }

    public function test_password_reset_rejects_an_invalid_token(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $original = $user->password;

        $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'BrandNewPass123!',
            'password_confirmation' => 'BrandNewPass123!',
        ])->assertSessionHasErrors('email');

        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_login_page_links_to_forgot_password(): void
    {
        $contents = (string) file_get_contents(resource_path('js/pages/Auth/Login.tsx'));

        $this->assertStringContainsString('href="/forgot-password"', $contents);
    }

    public function test_guests_cannot_open_the_profile_page(): void
    {
        $this->get('/settings/profile')->assertRedirect('/login');
    }

    public function test_profile_page_renders_for_signed_in_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/settings/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Profile'));
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/profile')
            ->put('/user/profile-information', [
                'name' => 'New Name',
                'email' => 'new@example.com',
            ])
            ->assertRedirect('/settings/profile')
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('New Name', $user->name);
        $this->assertSame('new@example.com', $user->email);
    }

    public function test_profile_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/profile')
            ->put('/user/profile-information', [
                'name' => $user->name,
                'email' => 'taken@example.com',
            ])
            ->assertSessionHasErrorsIn('updateProfileInformation', 'email');
    }

    public function test_password_can_be_changed_with_the_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPassword123!')]);

        $this->actingAs($user)
            ->from('/settings/profile')
            ->put('/user/password', [
                'current_password' => 'OldPassword123!',
                'password' => 'BrandNewPass123!',
                'password_confirmation' => 'BrandNewPass123!',
            ])
            ->assertRedirect('/settings/profile')
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('BrandNewPass123!', $user->fresh()->password));
    }

    public function test_password_change_requires_the_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('OldPassword123!')]);

        $this->actingAs($user)
            ->from('/settings/profile')
            ->put('/user/password', [
                'current_password' => 'wrong-password',
                'password' => 'BrandNewPass123!',
                'password_confirmation' => 'BrandNewPass123!',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->assertTrue(Hash::check('OldPassword123!', $user->fresh()->password));
    }
}
