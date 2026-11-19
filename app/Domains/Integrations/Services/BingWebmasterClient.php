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

            // Bing returns date e.g. "/Date(1620000000000)/" or ISO string
            $rawDate = (string) ($row['Date'] ?? '');
            if (preg_match('/\/Date\((\d+)\)\//', $rawDate, $matches)) {
                $date = date('Y-m-d', (int) ($matches[1] / 1000));
            } elseif ($rawDate !== '') {
                $date = date('Y-m-d', strtotime($rawDate) ?: time());
            } else {
                $date = date('Y-m-d');
            }

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
     * Fetch crawl statistics and crawl issues from Bing Webmaster Tools.
     *
     * @return list<array<string, mixed>>
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

        /** @var list<array<string, mixed>> */
        return is_array($d) ? $d : [];
    }
}
