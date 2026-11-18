<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Services;

use App\Domains\Projects\Models\Project;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Client for dispatching bounded crawl/verification jobs to the headless Rust `coqui-worker` sidecar.
 */
class CoquiWorkerClient
{
    /**
     * Dispatch a bounded cloud crawl for a project (scheduled audit or post-deploy smoke test).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function dispatchCrawl(Project $project, string $crawlId, array $overrides = []): array
    {
        $payload = [
            'crawlId' => $crawlId,
            'projectId' => $project->id,
            'seedKind' => $overrides['seedKind'] ?? 'url',
            'seed' => $overrides['seed'] ?? $project->canonical_url,
            'config' => [
                'maxDepth' => $overrides['maxDepth'] ?? 3,
                'maxUrls' => $overrides['maxUrls'] ?? (int) config('coqui.worker.default_max_urls', 1000),
                'concurrency' => $overrides['concurrency'] ?? 4,
                'requestTimeoutMs' => 15000,
                'sameHostOnly' => true,
                'respectRobots' => true,
            ],
        ];

        $response = $this->http()->post($this->url('/jobs/crawl'), $payload);
        $response->throw();

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json() ?? [];

        return $decoded;
    }

    /**
     * Inspect specific external URLs (backlink verification or SERP top-10 scraping).
     *
     * @param  list<string>  $urls
     * @return array<string, mixed>
     */
    public function inspectUrls(array $urls, ?string $targetDomain = null): array
    {
        $response = $this->http()->post($this->url('/jobs/inspect'), [
            'urls' => array_values($urls),
            'targetDomain' => $targetDomain,
        ]);

        $response->throw();

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json() ?? [];

        return $decoded;
    }

    private function http(): PendingRequest
    {
        $token = (string) config('coqui.worker.token');
        $request = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('coqui.worker.timeout', 30));

        if ($token !== '') {
            $request = $request->withToken($token);
        }

        return $request;
    }

    private function url(string $path): string
    {
        $base = rtrim((string) config('coqui.worker.url'), '/');

        return $base.'/'.ltrim($path, '/');
    }
}
