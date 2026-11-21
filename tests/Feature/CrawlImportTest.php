<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Crawls\Models\CrawlImport;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\Workspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CrawlImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // A configured bucket: the endpoints answer 503 without one.
        config()->set('filesystems.disks.r2', [
            'driver' => 's3',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'auto',
            'bucket' => 'test-bucket',
            'endpoint' => 'https://r2.test',
            'visibility' => 'private',
        ]);
        config()->set('coqui.imports.disk', 'r2');

        Storage::fake('r2');
        Storage::disk('r2')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration): string => 'https://r2.test/'.ltrim($path, '/').'?signature=test'
        );

        $this->user = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Imports',
            'slug' => 'imports',
            'owner_id' => $this->user->id,
            'plan_tier' => 'free',
        ]);
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);

        $this->project = Project::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Coqui',
            'domain' => 'coqui.io',
            'canonical_url' => 'https://coqui.io/',
            'location_code' => 2840,
            'language_code' => 'en',
        ]);

        Sanctum::actingAs($this->user, ['crawls:sync', 'crawls:read', 'projects:read']);
    }

    public function test_it_reserves_an_upload_and_returns_a_presigned_url(): void
    {
        $response = $this->postJson('/api/v1/projects/'.$this->project->uuid.'/imports', [
            'filename' => 'https-adamgreenwell-com-.dbseospider',
            'size_bytes' => 1024,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', CrawlImport::STATUS_PENDING);
        $this->assertStringContainsString('ws/'.$this->workspace->uuid.'/projects/'.$this->project->uuid.'/imports/', (string) $response->json('upload.url'));
        $this->assertSame('https-adamgreenwell-com-.dbseospider', $response->json('data.filename'));

        $import = CrawlImport::query()->sole();
        $this->assertSame($this->project->id, $import->project_id);
        $this->assertSame('r2', $import->disk);
        $this->assertNotNull($import->expires_at);
    }

    public function test_it_rejects_a_file_larger_than_the_plan_allows(): void
    {
        // Free allows 25 MB.
        $response = $this->postJson('/api/v1/projects/'.$this->project->uuid.'/imports', [
            'filename' => 'huge.dbseospider',
            'size_bytes' => 30 * 1024 * 1024,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('size_bytes');
        $this->assertSame(0, CrawlImport::query()->count());
    }

    public function test_it_rejects_an_unsupported_file_type(): void
    {
        $response = $this->postJson('/api/v1/projects/'.$this->project->uuid.'/imports', [
            'filename' => 'payload.exe',
            'size_bytes' => 1024,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('filename');
    }

    public function test_it_completes_once_the_file_lands_in_the_bucket(): void
    {
        $created = $this->postJson('/api/v1/projects/'.$this->project->uuid.'/imports', [
            'filename' => 'site.dbseospider',
            'size_bytes' => 2048,
        ])->assertCreated();

        $import = CrawlImport::query()->sole();
        Storage::disk('r2')->put($import->object_key, str_repeat('x', 2048));

        $response = $this->postJson('/api/v1/imports/'.$created->json('data.id').'/complete');

        $response->assertOk();
        $response->assertJsonPath('data.status', CrawlImport::STATUS_UPLOADED);
        $this->assertSame(2048, $import->fresh()->size_bytes);
        $this->assertNull($import->fresh()->expires_at);
    }

    public function test_it_refuses_to_complete_when_nothing_was_uploaded(): void
    {
        $created = $this->postJson('/api/v1/projects/'.$this->project->uuid.'/imports', [
            'filename' => 'missing.dbseospider',
            'size_bytes' => 1024,
        ])->assertCreated();

        $response = $this->postJson('/api/v1/imports/'.$created->json('data.id').'/complete');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('import');
        $this->assertSame(CrawlImport::STATUS_PENDING, CrawlImport::query()->sole()->status);
    }

    public function test_it_deletes_the_object_when_the_client_cancels(): void
    {
        $created = $this->postJson('/api/v1/projects/'.$this->project->uuid.'/imports', [
            'filename' => 'cancel.dbseospider',
            'size_bytes' => 1024,
        ])->assertCreated();

        $import = CrawlImport::query()->sole();
        Storage::disk('r2')->put($import->object_key, 'x');

        $this->deleteJson('/api/v1/imports/'.$created->json('data.id'))->assertNoContent();

        $this->assertSame(0, CrawlImport::query()->count());
        Storage::disk('r2')->assertMissing($import->object_key);
    }

    public function test_prune_removes_expired_uploads_and_their_objects(): void
    {
        $created = $this->postJson('/api/v1/projects/'.$this->project->uuid.'/imports', [
            'filename' => 'expired.dbseospider',
            'size_bytes' => 1024,
        ])->assertCreated();

        $import = CrawlImport::query()->sole();
        Storage::disk('r2')->put($import->object_key, 'x');
        $import->update(['expires_at' => now()->subHour()]);

        $this->artisan('imports:prune')->assertSuccessful();

        $this->assertNull(CrawlImport::query()->find($import->id));
        Storage::disk('r2')->assertMissing($import->object_key);
        $this->assertNotNull($created->json('data.id'));
    }

    public function test_imports_are_scoped_to_their_workspace(): void
    {
        $created = $this->postJson('/api/v1/projects/'.$this->project->uuid.'/imports', [
            'filename' => 'private.dbseospider',
            'size_bytes' => 1024,
        ])->assertCreated();

        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger, ['crawls:sync', 'crawls:read']);

        $this->getJson('/api/v1/imports/'.$created->json('data.id'))->assertNotFound();
    }

    public function test_it_answers_503_when_no_bucket_is_configured(): void
    {
        config()->set('filesystems.disks.r2', ['driver' => 's3', 'bucket' => null, 'key' => null, 'secret' => null, 'endpoint' => null]);

        $response = $this->postJson('/api/v1/projects/'.$this->project->uuid.'/imports', [
            'filename' => 'site.dbseospider',
            'size_bytes' => 1024,
        ]);

        $response->assertStatus(503);
        $response->assertJsonPath('storage_not_configured', true);
    }
}
