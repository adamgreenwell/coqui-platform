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

    /** Cap on a gzipped sitemap's decompressed size. */
    private const MAX_DECODED_BYTES = 20 * 1024 * 1024;

    private const SITEMAP_NAMESPACE = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    public function __construct(
        private readonly SafeHttpClient $http = new SafeHttpClient,
    ) {}

    /**
     * @return array{
     *     robots: array{url: string, status: int|null, disallow_all: bool, hash: string|null, sitemaps: list<string>},
     *     sitemap: array{url: string|null, status: int|null, valid: bool, url_count: int|null, too_large: bool}
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
        $sitemap = ['url' => $sitemapUrl, 'status' => null, 'valid' => false, 'url_count' => null, 'too_large' => false];
        try {
            $response = $this->http->get($sitemapUrl, timeoutSeconds: 10, maxBytes: self::MAX_BYTES);
            $sitemap['status'] = $response->status();
            if ($response->successful()) {
                $body = self::decodeSitemap($response->body());
                if ($body === null) {
                    // Gzipped past the decompressed cap (or corrupt): can't be checked.
                    $sitemap['too_large'] = true;
                } else {
                    $count = self::countSitemapEntries($body);
                    $sitemap['valid'] = $count !== null;
                    $sitemap['url_count'] = $count;
                }
            }
        } catch (Throwable $e) {
            // Over the size limit: it exists but can't be checked, which is
            // not the same as missing. Otherwise unreachable (status null).
            $sitemap['too_large'] = self::isSizeLimit($e);
        }

        return ['robots' => $robots, 'sitemap' => $sitemap];
    }

    /**
     * A sitemap.xml.gz served without Content-Encoding arrives still gzipped.
     */
    private static function decodeSitemap(string $body): ?string
    {
        if (! str_starts_with($body, "\x1f\x8b")) {
            return $body;
        }
        $decoded = @gzdecode($body, self::MAX_DECODED_BYTES);

        return is_string($decoded) ? $decoded : null;
    }

    private static function isSizeLimit(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (str_contains($current->getMessage(), 'exceeds the maximum allowed size')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether robots.txt blocks the whole site for all crawlers (`*`) or for
     * Googlebot, and which sitemaps are listed.
     *
     * Rules follow Google's robots.txt handling: a crawler obeys only the
     * most specific group naming it (falling back to `*`), and for a path the
     * longest matching rule wins, with Allow winning a tie. The site counts as
     * blocked when both the homepage and an ordinary page are disallowed.
     *
     * @return array{disallow_all: bool, sitemaps: list<string>}
     */
    public static function parseRobots(string $body): array
    {
        $sitemaps = [];
        /** @var list<array{agents: list<string>, rules: list<array{0: bool, 1: string}>}> $groups */
        $groups = [];
        $current = null;
        $lastWasAgent = false;
        // A UTF-8 byte order mark would otherwise stick to the first key.
        $body = (string) preg_replace('/^\xEF\xBB\xBF/', '', $body);

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
                    $groups[] = ['agents' => [], 'rules' => []];
                    $current = array_key_last($groups);
                }
                $groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;

                continue;
            }

            $lastWasAgent = false;
            // An empty Disallow allows everything, so it adds no rule.
            if ($current === null || $value === '' || ! in_array($key, ['allow', 'disallow'], true)) {
                continue;
            }
            $groups[$current]['rules'][] = [$key === 'allow', $value];
        }

        $rulesFor = static function (string $agent) use ($groups): ?array {
            $rules = null;
            foreach ($groups as $group) {
                if (in_array($agent, $group['agents'], true)) {
                    $rules = array_merge($rules ?? [], $group['rules']);
                }
            }

            return $rules;
        };

        $wildcard = $rulesFor('*') ?? [];
        $googlebot = $rulesFor('googlebot') ?? $wildcard;

        $blocksSite = static fn (array $rules): bool => ! self::isAllowed($rules, '/')
            && ! self::isAllowed($rules, '/coqui-smoke-check');

        return [
            'disallow_all' => $blocksSite($wildcard) || $blocksSite($googlebot),
            'sitemaps' => array_values(array_unique($sitemaps)),
        ];
    }

    /**
     * @param  list<array{0: bool, 1: string}>  $rules  [isAllow, pattern]
     */
    private static function isAllowed(array $rules, string $path): bool
    {
        $best = null;
        foreach ($rules as [$allow, $pattern]) {
            if (! self::patternMatches($pattern, $path)) {
                continue;
            }
            $length = strlen($pattern);
            if ($best === null || $length > $best[1] || ($length === $best[1] && $allow)) {
                $best = [$allow, $length];
            }
        }

        return $best === null || $best[0];
    }

    /**
     * robots.txt path matching: `*` matches any run of characters and a
     * trailing `$` anchors the end; otherwise the pattern is a prefix.
     */
    private static function patternMatches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }

        $regex = '#^'.implode('.*', array_map(static fn (string $part): string => preg_quote($part, '#'), explode('*', $pattern))).($anchored ? '$' : '').'#';

        return preg_match($regex, $path) === 1;
    }

    /**
     * Number of <url> (or <sitemap>, for an index) entries, or null when the
     * body isn't a sitemap. Entries are counted in the sitemap namespace,
     * whether it's the default namespace or a prefix like `<s:url>`.
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

        $entry = match (strtolower($xml->getName())) {
            'urlset' => 'url',
            'sitemapindex' => 'sitemap',
            default => null,
        };
        if ($entry === null) {
            return null;
        }

        $namespaced = count($xml->children(self::SITEMAP_NAMESPACE)->{$entry});
        $bare = count($xml->children()->{$entry});

        return max($namespaced, $bare);
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
