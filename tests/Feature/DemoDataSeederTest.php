<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No provider credentials, and no outbound calls allowed.
        config()->set('coqui.dataforseo.login', null);
        config()->set('coqui.dataforseo.password', null);
        config()->set('coqui.seranking.api_key', null);
        config()->set('coqui.serphouse.api_key', null);
        Http::preventStrayRequests();
    }

    public function test_seeding_gives_a_working_login_and_a_populated_project(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->post('/login', ['email' => config('coqui.demo.email'), 'password' => config('coqui.demo.password')]);
        $this->assertAuthenticated();

        /** @var Project $project */
        $project = Project::query()->where('domain', DemoDataSeeder::PROJECT_DOMAIN)->firstOrFail();
        $this->assertSame(2, $project->competitors()->count());
        $this->assertSame(2, $project->crawlRuns()->count());
        $this->assertSame(8, $project->trackedKeywords()->count());
        $this->assertSame(90, $project->backlinkHistorySnapshots()->count());
        $this->assertSame(6, $project->monitoredBacklinks()->count());
    }

    public function test_project_pages_render_the_seeded_data_without_provider_credentials(): void
    {
        $this->seed(DemoDataSeeder::class);

        /** @var User $user */
        $user = User::query()->where('email', config('coqui.demo.email'))->firstOrFail();
        /** @var Project $project */
        $project = Project::query()->where('domain', DemoDataSeeder::PROJECT_DOMAIN)->firstOrFail();
        /** @var Workspace $workspace */
        $workspace = $project->workspace;
        $base = "/workspaces/{$workspace->uuid}/projects/{$project->uuid}";

        $this->actingAs($user)->get($base)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('trackedKeywordsCount', 8)
                ->where('monitoredBacklinksCount', 6)
                ->etc());

        $this->actingAs($user)->get("{$base}/keywords")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('keywords', 8)->etc());

        $this->actingAs($user)->get("{$base}/backlinks")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('timeline', 90)->etc());

        // Seeded states use the watchlist's own vocabulary, so every metric counts them.
        $this->actingAs($user)->get("{$base}/backlinks/watchlist")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('metrics.active', 2)
                ->where('metrics.rel_changed', 1)
                ->where('metrics.lost', 2)
                ->where('metrics.error', 1)
                ->etc());
        $this->actingAs($user)->get("{$base}/bot-logs")->assertOk();
        $this->actingAs($user)->get("{$base}/crawls/compare")->assertOk();
    }

    public function test_running_the_seeder_again_leaves_the_demo_data_alone(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(1, Workspace::query()->where('slug', DemoDataSeeder::WORKSPACE_SLUG)->count());
        $this->assertSame(1, Project::query()->where('domain', DemoDataSeeder::PROJECT_DOMAIN)->count());
    }

    public function test_an_existing_account_with_the_demo_email_is_left_untouched(): void
    {
        /** @var User $existing */
        $existing = User::factory()->create(['email' => config('coqui.demo.email'), 'name' => 'Real Person']);
        $originalHash = $existing->password;

        try {
            $this->seed(DemoDataSeeder::class);
            $this->fail('The demo seeder reused an existing account.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('DEMO_USER_EMAIL', $e->getMessage());
        }

        $existing->refresh();
        $this->assertSame('Real Person', $existing->name);
        $this->assertSame($originalHash, $existing->password);
        $this->assertSame(0, Workspace::query()->where('slug', DemoDataSeeder::WORKSPACE_SLUG)->count());
    }

    public function test_the_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->seed(DemoDataSeeder::class);
            $this->fail('The demo seeder ran in production.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('production', $e->getMessage());
        }

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Workspace::query()->count());
    }
}
