<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrawlSyncApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_can_sync_chunk_and_finalize_crawl(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Test Workspace',
            'slug' => 'test-workspace',
            'owner_id' => $user->id,
        ]);
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Coqui SEO',
            'domain' => 'coquiseo.com',
            'canonical_url' => 'https://coquiseo.com/',
        ]);

        Sanctum::actingAs($user, ['crawls:sync']);

        $crawlId = (string) Str::uuid();

        $this->postJson("/api/v1/projects/{$project->id}/crawls/sync", [
            'id' => $crawlId,
            'seed' => 'https://coquiseo.com/',
            'seedKind' => 'url',
            'status' => 'running',
            'config' => [
                'maxDepth' => 3,
                'maxUrls' => 500,
                'authUser' => 'demo',
                'authPassword' => 'secret123',
                'customHeaders' => ['Authorization: Bearer abc'],
            ],
            'createdAt' => 1795022400000,
            'totalUrls' => 1,
        ])->assertCreated();

        $this->postJson("/api/v1/projects/{$project->id}/crawls/{$crawlId}/chunks", [
            'urls' => [
                [
                    'id' => 1,
                    'crawlId' => $crawlId,
                    'url' => 'https://coquiseo.com/',
                    'status' => 200,
                    'contentType' => 'text/html',
                    'title' => 'Coqui SEO',
                    'h1Count' => 1,
                    'depth' => 0,
                    'wordCount' => 320,
                    'discoveredAt' => 1795022401000,
                ],
            ],
            'links' => [
                [
                    'fromUrl' => 'https://coquiseo.com/',
                    'toUrl' => 'https://coquiseo.com/pricing',
                    'anchor' => 'Pricing',
                    'rel' => null,
                    'internal' => true,
                ],
            ],
            'issues' => [
                [
                    'crawlId' => $crawlId,
                    'url' => 'https://coquiseo.com/',
                    'rule' => 'missing_meta_description',
                    'severity' => 'warning',
                    'detail' => null,
                ],
            ],
        ])->assertOk();

        $this->postJson("/api/v1/projects/{$project->id}/crawls/{$crawlId}/finalize", [
            'status' => 'completed',
        ])
            ->assertOk()
            ->assertJson([
                'externalCrawlId' => $crawlId,
                'status' => 'completed',
                'totalUrls' => 1,
                'errors' => 0,
                'warnings' => 1,
                'opportunities' => 0,
            ]);

        $run = $project->crawlRuns()->where('external_crawl_id', $crawlId)->firstOrFail();
        $this->assertArrayNotHasKey('authPassword', $run->config);
        $this->assertSame('[redacted]', $run->config['authUser']);
    }
}
