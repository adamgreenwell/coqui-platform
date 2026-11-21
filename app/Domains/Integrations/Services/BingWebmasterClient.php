<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ProjectIntegration;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BingWebmasterClient
{
    /**
     * Fetch search query performance statistics from Bing Webmaster Tools.
     *
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: float, date: string}>
     */
    public function getQueryStats(ProjectIntegration $integration): array
    {
        $siteUrl = $integration->external_identifier;
        if (! is_string($siteUrl) || trim($siteUrl) === '') {
            throw new RuntimeException('Bing Webmaster Tools site URL is not configured.');
        }

        /** @var array<string, mixed>|null $creds */
        $creds = $integration->credentials_encrypted;
        $apiKey = (string) ($creds['api_key'] ?? config('services.bing.api_key') ?? '');

        if ($apiKey === '') {
            $integration->markNeedsReconnect('Bing Webmaster API key is missing.');
            throw new SearchConsoleAuthException('Bing Webmaster API key is not configured.');
        }

        $baseUrl = (string) config('services.bing.base_url', 'https://ssl.bing.com/webmaster/api.svc/json');
        $endpoint = $baseUrl.'/GetQueryStats';

        $response = Http::get($endpoint, [
            'siteUrl' => $siteUrl,
            'apikey' => $apiKey,
        ]);

        if ($response->status() === 401 || $response->status() === 403) {
            $integration->markNeedsReconnect('Bing API key invalid or unauthorized.');
            throw new SearchConsoleAuthException('Bing Webmaster authorization failed.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Bing Webmaster query stats request failed: '.$response->body());
        }

        $d = $response->json('d') ?? [];
        if (! is_array($d)) {
            return [];
        }

        $results = [];
        foreach ($d as $row) {
            if (! is_array($row)) {
                continue;
            }

            $query = isset($row['Query']) && is_string($row['Query']) ? trim($row['Query']) : '';
            if ($query === '') {
                continue;
            }

            $clicks = isset($row['Clicks']) && is_numeric($row['Clicks']) ? (int) $row['Clicks'] : 0;
            $impressions = isset($row['Impressions']) && is_numeric($row['Impressions']) ? (int) $row['Impressions'] : 0;
            $ctr = $impressions > 0 ? (float) ($clicks / $impressions) : 0.0;
            $position = isset($row['AvgImpressionPosition']) && is_numeric($row['AvgImpressionPosition'])
                ? (float) $row['AvgImpressionPosition']
                : (float) ($row['AvgClickPosition'] ?? 0.0);

            $date = self::parseDate($row['Date'] ?? null) ?? date('Y-m-d');

            $results[] = [
                'query' => $query,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => round($ctr, 4),
                'position' => round($position, 2),
                'date' => $date,
            ];
        }

        return $results;
    }

    /**
     * Bing dates come as "/Date(1620000000000)/" (optionally with a "-0700"
     * offset) or as an ISO string. Returns Y-m-d (UTC), or null when absent
     * or unreadable.
     */
    public static function parseDate(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        if (preg_match('#/Date\((-?\d+)(?:[+-]\d{4})?\)/#', $raw, $matches) === 1) {
            return gmdate('Y-m-d', intdiv((int) $matches[1], 1000));
        }

        $timestamp = strtotime($raw);

        return $timestamp !== false ? gmdate('Y-m-d', $timestamp) : null;
    }

    /**
     * Fetch daily crawl stats from Bing Webmaster Tools. Returns no rows when
     * the site or key is missing or the request fails; query stats report
     * those problems.
     *
     * @return list<array{date: string, crawled_pages: int, crawl_errors: int, in_index: int, in_links: int, code_2xx: int, code_301: int, code_302: int, code_4xx: int, code_5xx: int, blocked_by_robots: int, dns_failures: int, connection_timeouts: int}>
     */
    public function getCrawlStats(ProjectIntegration $integration): array
    {
        $siteUrl = $integration->external_identifier;
        if (! is_string($siteUrl) || trim($siteUrl) === '') {
            return [];
        }

        /** @var array<string, mixed>|null $creds */
        $creds = $integration->credentials_encrypted;
        $apiKey = (string) ($creds['api_key'] ?? config('services.bing.api_key') ?? '');

        if ($apiKey === '') {
            return [];
        }

        $baseUrl = (string) config('services.bing.base_url', 'https://ssl.bing.com/webmaster/api.svc/json');
        $endpoint = $baseUrl.'/GetCrawlStats';

        $response = Http::get($endpoint, [
            'siteUrl' => $siteUrl,
            'apikey' => $apiKey,
        ]);

        if (! $response->successful()) {
            return [];
        }

        $d = $response->json('d') ?? [];
        if (! is_array($d)) {
            return [];
        }

        $int = static fn (array $row, string $key): int => isset($row[$key]) && is_numeric($row[$key]) ? max(0, (int) $row[$key]) : 0;

        $results = [];
        foreach ($d as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = self::parseDate($row['Date'] ?? null);
            if ($date === null) {
                continue;
            }

            $results[] = [
                'date' => $date,
                'crawled_pages' => $int($row, 'CrawledPages'),
                'crawl_errors' => $int($row, 'CrawlErrors'),
                'in_index' => $int($row, 'InIndex'),
                'in_links' => $int($row, 'InLinks'),
                'code_2xx' => $int($row, 'Code2xx'),
                'code_301' => $int($row, 'Code301'),
                'code_302' => $int($row, 'Code302'),
                'code_4xx' => $int($row, 'Code4xx'),
                'code_5xx' => $int($row, 'Code5xx'),
                'blocked_by_robots' => $int($row, 'BlockedByRobotsTxt'),
                'dns_failures' => $int($row, 'DnsFailures'),
                'connection_timeouts' => $int($row, 'ConnectionTimeout'),
            ];
        }

        return $results;
    }
}
