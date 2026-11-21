<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Bulk "track" from Keyword Explorer and Keyword Gap respects the plan's
 * tracked keyword limit, counting only keywords that are actually new.
 */
class BulkKeywordTrackingLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Limits',
            'slug' => 'limits',
            'owner_id' => $this->user->id,
            'tracked_keyword_limit' => 3,
        ]);
        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Coqui',
            'domain' => 'coqui.io',
            'canonical_url' => 'https://coqui.io/',
            'location_code' => 2840,
            'language_code' => 'en',
        ]);

        foreach (['existing one', 'existing two'] as $keyword) {
            TrackedKeyword::query()->create([
                'project_id' => $this->project->id,
                'keyword' => $keyword,
                'device' => 'desktop',
                'location_code' => 2840,
                'language_code' => 'en',
                'cadence' => 'weekly',
            ]);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function endpoints(): array
    {
        return [
            'keyword explorer' => ['keyword-explorer'],
            'keyword gap' => ['keyword-gap'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_a_batch_over_the_limit_is_refused_and_nothing_is_added(string $endpoint): void
    {
        // One slot left, two new keywords.
        $this->track($endpoint, ['existing one', 'new one', 'new two'])
            ->assertStatus(422)
            ->assertJsonPath('errors.keywords.0', fn (string $message): bool => str_contains($message, 'plan limit of 3'));

        $this->assertSame(2, TrackedKeyword::query()->count());
    }

    #[DataProvider('endpoints')]
    public function test_existing_keywords_dont_use_up_the_limit(string $endpoint): void
    {
        // Re-adding both existing keywords plus one new one fits exactly.
        $this->track($endpoint, ['existing one', 'existing two', 'new one'])->assertOk();

        $this->assertSame(3, TrackedKeyword::query()->count());
    }

    #[DataProvider('endpoints')]
    public function test_a_full_workspace_can_still_reactivate_an_existing_keyword(string $endpoint): void
    {
        $this->workspace->update(['tracked_keyword_limit' => 2]);
        TrackedKeyword::query()->where('keyword', 'existing one')->update(['is_active' => false]);

        $this->track($endpoint, ['existing one'])->assertOk();

        $this->assertTrue((bool) TrackedKeyword::query()->where('keyword', 'existing one')->value('is_active'));
    }

    /**
     * @param  list<string>  $keywords
     */
    private function track(string $endpoint, array $keywords): TestResponse
    {
        return $this->actingAs($this->user)->postJson(
            "/workspaces/{$this->workspace->uuid}/projects/{$this->project->uuid}/{$endpoint}/track",
            [
                'keywords' => array_map(fn (string $keyword): array => ['keyword' => $keyword], $keywords),
                'device' => 'desktop',
                'cadence' => 'weekly',
            ],
        );
    }
}
