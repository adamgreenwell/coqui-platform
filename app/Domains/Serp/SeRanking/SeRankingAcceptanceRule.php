<?php

declare(strict_types=1);

namespace App\Domains\Serp\SeRanking;

/**
 * Filters and partitions queries before calling SE Ranking's Data API.
 *
 * The SE Ranking `us` keyword database rejects an entire batch if any keyword
 * contains non-ASCII characters, lacks alphabetic letters (e.g. bare years),
 * or resembles machine-generated search-operator queries.
 */
class SeRankingAcceptanceRule
{
    public function accepts(string $keyword, string $source = 'us'): bool
    {
        $trimmed = trim($keyword);
        if ($trimmed === '') {
            return false;
        }

        if (mb_strlen($trimmed) > 80) {
            return false;
        }

        $words = preg_split('/\s+/', $trimmed);
        if (is_array($words) && count($words) > 10) {
            return false;
        }

        if (preg_match('/["+%]/', $trimmed) === 1 || preg_match('/\b(site|inurl|intitle):/i', $trimmed) === 1) {
            return false;
        }

        if (strtolower($source) === 'us' && preg_match('/^[\x20-\x7E]+$/', $trimmed) !== 1) {
            return false;
        }

        if (preg_match('/[a-zA-Z]/', $trimmed) !== 1) {
            return false;
        }

        return true;
    }

    /**
     * Normalize and partition keywords into SE Ranking-safe vs DataForSEO fallback buckets.
     *
     * @param  list<string>  $keywords
     * @return array{accepted: list<string>, rejected: list<string>}
     */
    public function partition(array $keywords, string $source = 'us'): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (string $keyword): string => mb_strtolower(trim($keyword)),
            $keywords,
        ), static fn (string $keyword): bool => $keyword !== '')));

        $accepted = [];
        $rejected = [];

        foreach ($normalized as $keyword) {
            if ($this->accepts($keyword, $source)) {
                $accepted[] = $keyword;
            } else {
                $rejected[] = $keyword;
            }
        }

        return [
            'accepted' => $accepted,
            'rejected' => $rejected,
        ];
    }
}
