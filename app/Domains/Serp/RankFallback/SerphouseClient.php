<?php

declare(strict_types=1);

namespace App\Domains\Serp\RankFallback;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Live Google results from SERPHouse, one page of 10 at a time. Its
 * multi-page endpoints are retired, so deeper checks request each page.
 */
class SerphouseClient
{
    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the request fails; SERPHouse doesn't
     *                          charge for failed requests
     */
    public function googlePage(string $keyword, string $language, string $device, int $locationCode, int $page): array
    {
        $apiKey = (string) config('coqui.serphouse.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('SERPHouse API key is not configured.');
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('coqui.serphouse.timeout', 30))
            ->post(rtrim((string) config('coqui.serphouse.base_url'), '/').'/google-web', [
                'data' => [
                    'q' => $keyword,
                    'domain' => (string) config('coqui.serphouse.google_domain', 'google.com'),
                    'lang' => $language,
                    'device' => $device === 'mobile' ? 'mobile' : 'desktop',
                    // SERPHouse uses Google's geo target IDs, the same codes
                    // tracked keywords store.
                    'loc_id' => $locationCode,
                    'page' => $page,
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(match ($response->status()) {
                401 => 'SERPHouse rejected the API key.',
                402 => 'SERPHouse account is out of credits.',
                429 => 'SERPHouse rate limit reached.',
                default => "SERPHouse request failed: HTTP {$response->status()}",
            });
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('SERPHouse returned an unreadable response.');
        }

        // A 200 can still carry {"status": "error", "msg": "Please try again"}.
        if (($json['status'] ?? null) === 'error') {
            throw new RuntimeException('SERPHouse request failed: '.(string) ($json['msg'] ?? 'unknown error'));
        }

        return $json;
    }
}
