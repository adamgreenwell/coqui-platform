<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Crawls\Services\SafeHttpClient;
use SimpleXMLElement;
use Throwable;

/**
 * Fetches a deploy's robots.txt and sitemap directly, so the smoke test can
 * judge them even when the crawl itself was blocked or found nothing.
 */
class DeploySiteFilesInspector
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly SafeHttpClient $http = new SafeHttpClient,
    ) {}

    /**
     * @return array{
     *     robots: array{url: string, status: int|null, disallow_all: bool, hash: string|null, sitemaps: list<string>},
     *     sitemap: array{url: string|null, status: int|null, valid: bool, url_count: int|null}
     * }
     */
    public function inspect(string $seed): array
    {
        $origin = $this->origin($seed);
        $robotsUrl = $origin.'/robots.txt';

        $robots = ['url' => $robotsUrl, 'status' => null, 'disallow_all' => false, 'hash' => null, 'sitemaps' => []];
        try {
            $response = $this->http->get($robotsUrl, timeoutSeconds: 8, maxBytes: self::MAX_BYTES);
            $robots['status'] = $response->status();
            if ($response->successful()) {
                $body = $response->body();
                $parsed = self::parseRobots($body);
                $robots['disallow_all'] = $parsed['disallow_all'];
                $robots['sitemaps'] = $parsed['sitemaps'];
                $robots['hash'] = hash('sha256', trim($body));
            }
        } catch (Throwable) {
            // Unreachable robots.txt: reported as status null.
        }

        $sitemapUrl = $robots['sitemaps'][0] ?? $origin.'/sitemap.xml';
        $sitemap = ['url' => $sitemapUrl, 'status' => null, 'valid' => false, 'url_count' => null];
        try {
            $response = $this->http->get($sitemapUrl, timeoutSeconds: 10, maxBytes: self::MAX_BYTES);
            $sitemap['status'] = $response->status();
            if ($response->successful()) {
                $count = self::countSitemapEntries($response->body());
                $sitemap['valid'] = $count !== null;
                $sitemap['url_count'] = $count;
            }
        } catch (Throwable) {
            // Unreachable sitemap: reported as status null.
        }

        return ['robots' => $robots, 'sitemap' => $sitemap];
    }

    /**
     * Whether the rules for all crawlers (`*`) or Googlebot block the whole
     * site, and which sitemaps are listed.
     *
     * @return array{disallow_all: bool, sitemaps: list<string>}
     */
    public static function parseRobots(string $body): array
    {
        $sitemaps = [];
        /** @var list<array{agents: list<string>, disallow: list<string>, allow: list<string>}> $groups */
        $groups = [];
        $current = null;
        $lastWasAgent = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = strtolower($key);

            if ($key === 'sitemap') {
                if ($value !== '') {
                    $sitemaps[] = $value;
                }

                continue;
            }

            if ($key === 'user-agent') {
                if (! $lastWasAgent || $current === null) {
                    $groups[] = ['agents' => [], 'disallow' => [], 'allow' => []];
                    $current = array_key_last($groups);
                }
                $groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;

                continue;
            }

            $lastWasAgent = false;
            if ($current === null) {
                continue;
            }
            if ($key === 'disallow') {
                $groups[$current]['disallow'][] = $value;
            } elseif ($key === 'allow') {
                $groups[$current]['allow'][] = $value;
            }
        }

        $disallowAll = false;
        foreach ($groups as $group) {
            $applies = array_intersect($group['agents'], ['*', 'googlebot']) !== [];
            if (! $applies) {
                continue;
            }

            $blocksRoot = array_intersect($group['disallow'], ['/', '/*']) !== [];
            $allowsRoot = array_intersect($group['allow'], ['/', '/$']) !== [];
            if ($blocksRoot && ! $allowsRoot) {
                $disallowAll = true;
            }
        }

        return ['disallow_all' => $disallowAll, 'sitemaps' => array_values(array_unique($sitemaps))];
    }

    /**
     * Number of <url> (or <sitemap>, for an index) entries, or null when the
     * body isn't a sitemap.
     */
    public static function countSitemapEntries(string $body): ?int
    {
        if (trim($body) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $xml instanceof SimpleXMLElement) {
            return null;
        }

        return match (strtolower($xml->getName())) {
            'urlset' => $xml->count(),
            'sitemapindex' => $xml->count(),
            default => null,
        };
    }

    private function origin(string $seed): string
    {
        $parts = parse_url($seed);
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return "{$scheme}://{$host}{$port}";
    }
}
