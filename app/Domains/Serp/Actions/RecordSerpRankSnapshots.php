<?php

declare(strict_types=1);

namespace App\Domains\Serp\Actions;

use App\Domains\Projects\Models\ProjectActivity;
use App\Domains\Serp\Events\SerpBatchCompleted;
use App\Domains\Serp\Models\KeywordAiOverviewCitation;
use App\Domains\Serp\Models\KeywordRankSnapshot;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Domains\Serp\Services\SerpResultParser;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecordSerpRankSnapshots
{
    public function __construct(
        private readonly SerpResultParser $parser = new SerpResultParser,
    ) {}

    /**
     * Parse SERP result payload and record snapshots for project and all competitors.
     *
     * @param  array<string, mixed>|list<array<string, mixed>>  $serpPayload
     * @return list<KeywordRankSnapshot>
     */
    public function handle(
        TrackedKeyword $keyword,
        array $serpPayload,
        CarbonInterface|string|null $checkedOn = null,
    ): array {
        $project = $keyword->project()->with('competitors')->firstOrFail();
        $projectDomain = $project->domain;
        $competitorDomains = $project->competitors->pluck('domain')->all();

        $parsed = $this->parser->parse($serpPayload, $projectDomain, $competitorDomains);

        $date = $checkedOn instanceof CarbonInterface
            ? $checkedOn->toDateString()
            : ($checkedOn !== null ? Carbon::parse($checkedOn)->toDateString() : now()->toDateString());

        $savedSnapshots = [];

        foreach ($parsed['domain_snapshots'] as $domain => $data) {
            $features = $data['serp_features'];
            if ($domain === $projectDomain) {
                // The rest of the page, kept once per keyword and day on the
                // project's own snapshot.
                $features['ai_overview_position'] = $parsed['ai_overview']['position'];
                $features['ai_overview_citation_count'] = count($parsed['ai_overview']['citations']);
                $features['people_also_ask'] = $parsed['people_also_ask'];
                $features['local_pack'] = $parsed['local_pack'];
            }

            /** @var KeywordRankSnapshot $snapshot */
            $snapshot = KeywordRankSnapshot::query()->updateOrCreate(
                [
                    'tracked_keyword_id' => $keyword->id,
                    'domain' => $domain,
                    'checked_on' => $date,
                ],
                [
                    'project_id' => $project->id,
                    'rank_group' => $data['rank_group'],
                    'rank_absolute' => $data['rank_absolute'],
                    'ranking_url' => $data['ranking_url'],
                    'ranking_title' => $data['ranking_title'] !== null ? Str::limit((string) $data['ranking_title'], 255, '') : null,
                    'has_ai_overview' => $data['has_ai_overview'],
                    'has_featured_snippet' => $data['has_featured_snippet'],
                    'serp_features' => $features,
                ]
            );

            $savedSnapshots[] = $snapshot;
        }

        $this->recordCitations($keyword, $project->id, $date, $parsed['ai_overview']['citations']);

        $keyword->update(['last_checked_at' => now()]);

        // Realtime event broadcast on project.{id} channel (deferred until transaction commits)
        DB::afterCommit(function () use ($project, $savedSnapshots): void {
            $batchId = (string) Str::uuid();
            rescue(fn () => broadcast(new SerpBatchCompleted(
                projectId: $project->id,
                batchId: $batchId,
                keywordCount: 1,
                updatedCount: count($savedSnapshots),
                status: 'completed',
            )), report: true);
        });

        // Persistent audit log
        $projectRank = $parsed['domain_snapshots'][$projectDomain]['rank_group'] ?? null;
        $rankSummary = $projectRank !== null ? "ranked #{$projectRank}" : 'unranked (>100)';

        ProjectActivity::log(
            project: $project,
            eventType: 'serp.checked',
            title: Str::limit("Rank checked: {$keyword->keyword}", 255, ''),
            description: "SERP check recorded for '{$keyword->keyword}' ({$keyword->device}) — {$projectDomain} {$rankSummary}.",
            properties: [
                'tracked_keyword_id' => $keyword->id,
                'keyword' => $keyword->keyword,
                'device' => $keyword->device,
                'location_code' => $keyword->location_code,
                'project_rank' => $projectRank,
                'competitor_count' => count($competitorDomains),
                'detected_features' => $parsed['detected_features'],
            ]
        );

        return $savedSnapshots;
    }

    /**
     * Replace the day's AI Overview citations for this keyword.
     *
     * @param  list<array{position: int, domain: string, url: string|null, title: string|null}>  $citations
     */
    private function recordCitations(TrackedKeyword $keyword, int $projectId, string $date, array $citations): void
    {
        DB::transaction(function () use ($keyword, $projectId, $date, $citations): void {
            KeywordAiOverviewCitation::query()
                ->where('tracked_keyword_id', $keyword->id)
                ->whereDate('checked_on', $date)
                ->delete();

            if ($citations === []) {
                return;
            }

            $now = now();
            KeywordAiOverviewCitation::query()->insert(array_map(fn (array $citation): array => [
                'tracked_keyword_id' => $keyword->id,
                'project_id' => $projectId,
                'checked_on' => $date,
                'position' => $citation['position'],
                'domain' => Str::limit($citation['domain'], 255, ''),
                'url' => $citation['url'],
                'title' => $citation['title'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $citations));
        });
    }
}
