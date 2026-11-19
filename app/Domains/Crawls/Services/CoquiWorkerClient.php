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
    public function __construct(
        private readonly SsrfProtectionGuard $ssrf = new SsrfProtectionGuard(),
    ) {}

    /**
     * Dispatch a bounded cloud crawl for a project (scheduled audit or post-deploy smoke test).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function dispatchCrawl(Project $project, string $crawlId, array $overrides = []): array
    {
        $seed = (string) ($overrides['seed'] ?? $project->canonical_url);
        $this->ssrf->assertAllowed($seed);

        $workspaceLimit = $project->workspace?->cloud_crawl_url_limit;
        $defaultCap = (int) config('coqui.worker.default_max_urls', 1000);
        $effectiveMax = $workspaceLimit !== null && $workspaceLimit > 0 ? $workspaceLimit : $defaultCap;
        $requestedMax = (int) ($overrides['maxUrls'] ?? $effectiveMax);
        $boundedMaxUrls = min($requestedMax, $effectiveMax);

        $payload = [
            'crawlId' => $crawlId,
            'projectId' => $project->id,
            'seedKind' => $overrides['seedKind'] ?? 'url',
            'seed' => $seed,
            'config' => [
                'maxDepth' => $overrides['maxDepth'] ?? 3,
                'maxUrls' => $boundedMaxUrls,
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
        foreach ($urls as $url) {
            $this->ssrf->assertAllowed((string) $url);
        }

        $response = $this->http()->post($this->url('/jobs/inspect'), [
            'urls' => $urls,
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
