<?php

declare(strict_types=1);

namespace App\Domains\Serp\Support;

use App\Domains\Projects\Models\Project;
use App\Domains\Serp\Models\KeywordRankSnapshot;

/**
 * CTR-weighted visibility: each tracked keyword earns points by its current
 * position (100 for #1 down to 2 beyond #50), as a share of the maximum.
 */
final class VisibilityScore
{
    public static function points(?int $rank): int
    {
        return match (true) {
            $rank === null || $rank <= 0 => 0,
            $rank === 1 => 100,
            $rank === 2 => 70,
            $rank === 3 => 50,
            $rank <= 5 => 35,
            $rank <= 10 => 20,
            $rank <= 20 => 10,
            $rank <= 50 => 5,
            default => 2,
        };
    }

    /**
     * @param  list<int|null>  $ranks  Current rank per tracked keyword (null: not ranking).
     */
    public static function score(array $ranks): float
    {
        if ($ranks === []) {
            return 0.0;
        }

        $points = array_sum(array_map(self::points(...), $ranks));

        return round(($points / (count($ranks) * 100)) * 100, 1);
    }

    /**
     * The project's current visibility from each keyword's latest check.
     *
     * @return array{score: float|null, tracked: int, ranked: int, top10: int}
     */
    public static function forProject(Project $project): array
    {
        $keywordIds = $project->trackedKeywords()->pluck('id')->all();
        if ($keywordIds === []) {
            return ['score' => null, 'tracked' => 0, 'ranked' => 0, 'top10' => 0];
        }

        $latest = KeywordRankSnapshot::query()
            ->select('tracked_keyword_id', 'rank_group')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY tracked_keyword_id ORDER BY checked_on DESC, id DESC) as rn')
            ->whereIn('tracked_keyword_id', $keywordIds)
            ->where('domain', $project->domain);

        $ranksByKeyword = KeywordRankSnapshot::query()
            ->fromSub($latest, 'latest_snapshots')
            ->where('rn', 1)
            ->pluck('rank_group', 'tracked_keyword_id');

        // Nothing checked yet: there's no score to show.
        if ($ranksByKeyword->isEmpty()) {
            return ['score' => null, 'tracked' => count($keywordIds), 'ranked' => 0, 'top10' => 0];
        }

        // Same basis as the keywords page: every tracked keyword counts, and
        // one without a ranking scores nothing.
        $ranks = array_map(
            static fn (int $id): ?int => $ranksByKeyword->has($id) && $ranksByKeyword->get($id) !== null ? (int) $ranksByKeyword->get($id) : null,
            array_map(intval(...), $keywordIds),
        );

        return [
            'score' => self::score($ranks),
            'tracked' => count($keywordIds),
            'ranked' => count(array_filter($ranks, static fn (?int $r): bool => $r !== null && $r > 0)),
            'top10' => count(array_filter($ranks, static fn (?int $r): bool => $r !== null && $r > 0 && $r <= 10)),
        ];
    }
}
