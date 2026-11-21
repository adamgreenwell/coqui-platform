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
    public const string NOT_CONFIGURED_MESSAGE = 'Cloud crawling isn\'t set up on this server yet. An administrator needs to start the crawl worker.';

    public function __construct(
        private readonly SsrfProtectionGuard $ssrf = new SsrfProtectionGuard,
    ) {}

    /**
     * Whether a worker URL and token are set. Without a token the worker
     * refuses to start and Laravel rejects its callbacks, so nothing can run.
     */
    public function isConfigured(): bool
    {
        return trim((string) config('coqui.worker.url')) !== ''
            && trim((string) config('coqui.worker.token')) !== '';
    }

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
                'respectRobots' => (bool) ($overrides['respectRobots'] ?? true),
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
     * @param  array<string, string>  $targetUrls  Source URL => monitored target URL
     * @return array<string, mixed>
     */
    public function inspectUrls(array $urls, ?string $targetDomain = null, array $targetUrls = []): array
    {
        foreach ($urls as $url) {
            $this->ssrf->assertAllowed((string) $url);
        }

        $response = $this->http()->post($this->url('/jobs/inspect'), [
            'urls' => $urls,
            'targetDomain' => $targetDomain,
            // Source URL => the exact page the monitored link should point at.
            'targetUrls' => (object) $targetUrls,
        ]);

        $response->throw();

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json() ?? [];

        return $decoded;
    }

    private function http(): PendingRequest
    {
        $token = (string) config('coqui.worker.token');
        // A worker that's down should fail the request in seconds, not hold
        // a queue job for the full timeout.
        $request = Http::acceptJson()
            ->asJson()
            ->connectTimeout((int) config('coqui.worker.connect_timeout', 5))
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
