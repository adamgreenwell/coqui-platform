<?php

declare(strict_types=1);

namespace App\Domains\Serp\Services;

use App\Domains\Serp\Models\KeywordRankSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Daily rank trends for a set of tracked keywords on the project's own
 * domain. Each day uses every keyword's latest check on or before it, so
 * keywords checked weekly still count every day in between.
 */
class RankTrendService
{
    /** Rank used for "not in the top 100" when measuring movement. */
    private const UNRANKED = 101;

    /**
     * @param  list<int>  $keywordIds
     * @return list<array{
     *     date: string,
     *     tracked: int,
     *     visibility: float|null,
     *     avg_rank: float|null,
     *     top_3: int,
     *     top_10: int,
     *     top_20: int,
     *     top_100: int,
     *     unranked: int,
     *     volatility: float|null,
     *     checked: int,
     *     ai_overview: int,
     *     ai_overview_cited: int,
     * }>
     */
    public function daily(string $domain, array $keywordIds, int $days = 30): array
    {
        if ($keywordIds === [] || $days < 1) {
            return [];
        }

        $end = Carbon::today();
        $start = $end->copy()->subDays($days - 1);

        $startDate = $start->toDateString();
        $columns = ['tracked_keyword_id', 'rank_group', 'has_ai_overview', 'serp_features', 'checked_on'];
        $table = (new KeywordRankSnapshot)->getTable();

        $inWindow = KeywordRankSnapshot::query()
            ->whereIn('tracked_keyword_id', $keywordIds)
            ->where('domain', $domain)
            ->whereDate('checked_on', '>=', $startDate)
            ->get($columns);

        // Each keyword's latest check before the window, however old, seeds
        // its position on the first day and gives the first check in the
        // window something to move from. (Like the keywords table, an old
        // check still counts until a newer one arrives.)
        $seedDates = KeywordRankSnapshot::query()
            ->selectRaw('tracked_keyword_id, MAX(checked_on) AS seed_on')
            ->whereIn('tracked_keyword_id', $keywordIds)
            ->where('domain', $domain)
            ->whereDate('checked_on', '<', $startDate)
            ->groupBy('tracked_keyword_id');

        $seeds = KeywordRankSnapshot::query()
            ->joinSub($seedDates, 'seed', function ($join) use ($table): void {
                $join->on("{$table}.tracked_keyword_id", '=', 'seed.tracked_keyword_id')
                    ->on("{$table}.checked_on", '=', 'seed.seed_on');
            })
            ->where("{$table}.domain", $domain)
            ->get(array_map(fn (string $c): string => "{$table}.{$c}", $columns));

        $snapshots = $seeds->concat($inWindow)
            ->sortBy(fn (KeywordRankSnapshot $s): string => $this->dateOf($s))
            ->values();

        if ($snapshots->isEmpty()) {
            return [];
        }

        /** @var Collection<int, Collection<int, KeywordRankSnapshot>> $byKeyword */
        $byKeyword = $snapshots->groupBy(fn (KeywordRankSnapshot $s): int => (int) $s->tracked_keyword_id)
            ->map(fn (Collection $rows): Collection => $rows->values());

        $cursor = [];
        $current = [];
        $series = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();
            $moves = [];

            foreach ($byKeyword as $keywordId => $rows) {
                $i = $cursor[$keywordId] ?? 0;
                while ($i < $rows->count() && $this->dateOf($rows[$i]) <= $date) {
                    $snapshot = $rows[$i];
                    $previous = $current[$keywordId] ?? null;
                    if ($previous !== null && $this->dateOf($snapshot) === $date) {
                        $moves[] = abs($this->rankOrUnranked($snapshot) - $this->rankOrUnranked($previous));
                    }
                    $current[$keywordId] = $snapshot;
                    $i++;
                }
                $cursor[$keywordId] = $i;
            }

            $tracked = count($current);
            $points = 0;
            $rankSum = 0;
            $ranked = 0;
            $buckets = ['top_3' => 0, 'top_10' => 0, 'top_20' => 0, 'top_100' => 0];
            $aiOverview = 0;
            $cited = 0;

            foreach ($current as $snapshot) {
                $rank = $snapshot->rank_group;
                $points += $this->points($rank);
                if ($rank !== null && $rank > 0) {
                    $ranked++;
                    $rankSum += $rank;
                    $buckets['top_3'] += $rank <= 3 ? 1 : 0;
                    $buckets['top_10'] += $rank <= 10 ? 1 : 0;
                    $buckets['top_20'] += $rank <= 20 ? 1 : 0;
                    $buckets['top_100'] += $rank <= 100 ? 1 : 0;
                }
                if ($snapshot->has_ai_overview) {
                    $aiOverview++;
                    $cited += ! empty($snapshot->serp_features['ai_overview_cited']) ? 1 : 0;
                }
            }

            $series[] = [
                'date' => $date,
                'tracked' => $tracked,
                'visibility' => $tracked > 0 ? round($points / ($tracked * 100) * 100, 1) : null,
                'avg_rank' => $ranked > 0 ? round($rankSum / $ranked, 1) : null,
                'top_3' => $buckets['top_3'],
                'top_10' => $buckets['top_10'],
                'top_20' => $buckets['top_20'],
                'top_100' => $buckets['top_100'],
                'unranked' => $tracked - $ranked,
                // Average positions moved by the keywords re-checked that day.
                'volatility' => $moves !== [] ? round(array_sum($moves) / count($moves), 1) : null,
                'checked' => count($moves),
                'ai_overview' => $aiOverview,
                'ai_overview_cited' => $cited,
            ];
        }

        return $series;
    }

    /**
     * Average positions moved between consecutive checks.
     *
     * @param  iterable<KeywordRankSnapshot>  $snapshots  One domain, oldest first.
     */
    public function volatility(iterable $snapshots): ?float
    {
        $moves = [];
        $previous = null;
        foreach ($snapshots as $snapshot) {
            if ($previous !== null) {
                $moves[] = abs($this->rankOrUnranked($snapshot) - $this->rankOrUnranked($previous));
            }
            $previous = $snapshot;
        }

        return $moves !== [] ? round(array_sum($moves) / count($moves), 1) : null;
    }

    /** Same CTR weighting as the keywords page's visibility score. */
    private function points(?int $rank): int
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

    private function rankOrUnranked(KeywordRankSnapshot $snapshot): int
    {
        $rank = $snapshot->rank_group;

        return $rank !== null && $rank > 0 ? min($rank, self::UNRANKED) : self::UNRANKED;
    }

    private function dateOf(KeywordRankSnapshot $snapshot): string
    {
        return Carbon::parse($snapshot->checked_on)->toDateString();
    }
}
