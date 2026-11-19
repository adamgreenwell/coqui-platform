<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Services;

use App\Domains\Crawls\Exceptions\SsrfProtectionException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * HTTP client for server-side requests to user-supplied URLs.
 *
 * Every hop is checked by the SSRF guard: redirects are followed manually and
 * each Location is validated before it is requested, the connection is pinned
 * to the IPs the guard approved (closing the DNS-rebinding window between
 * check and fetch), and redirect count and response size are capped.
 */
class SafeHttpClient
{
    public const DEFAULT_USER_AGENT = 'Mozilla/5.0 (compatible; CoquiBot/1.0; +https://coquiseo.com/bot)';

    public const DEFAULT_MAX_REDIRECTS = 5;

    public const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly SsrfProtectionGuard $guard = new SsrfProtectionGuard,
    ) {}

    /**
     * GET a user-supplied URL, following redirects safely.
     *
     * @throws SsrfProtectionException when any hop targets a forbidden address
     */
    public function get(
        string $url,
        int $timeoutSeconds = 6,
        string $userAgent = self::DEFAULT_USER_AGENT,
        int $maxRedirects = self::DEFAULT_MAX_REDIRECTS,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
    ): Response {
        $currentUrl = $url;

        for ($hop = 0; ; $hop++) {
            $response = $this->pinnedRequest($currentUrl, $timeoutSeconds, $maxBytes)
                ->withUserAgent($userAgent)
                ->get($currentUrl);

            $this->assertWithinSize($response, $currentUrl, $maxBytes);

            $location = trim((string) $response->header('Location'));
            if (! in_array($response->status(), self::REDIRECT_STATUSES, true) || $location === '') {
                return $response;
            }

            if ($hop >= $maxRedirects) {
                throw SsrfProtectionException::blocked($url, sprintf('Exceeded %d redirects.', $maxRedirects));
            }

            $currentUrl = (string) UriResolver::resolve(new Uri($currentUrl), new Uri($location));
        }
    }

    /**
     * POST JSON to a user-supplied URL (for example a webhook callback).
     * Redirects are never followed for POSTs.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     *
     * @throws SsrfProtectionException
     */
    public function postJson(string $url, array $payload, array $headers = [], int $timeoutSeconds = 10): Response
    {
        $response = $this->pinnedRequest($url, $timeoutSeconds, self::DEFAULT_MAX_BYTES)
            ->asJson()
            ->withHeaders($headers)
            ->post($url, $payload);

        $this->assertWithinSize($response, $url, self::DEFAULT_MAX_BYTES);

        return $response;
    }

    /**
     * @throws SsrfProtectionException
     */
    private function pinnedRequest(string $url, int $timeoutSeconds, int $maxBytes): PendingRequest
    {
        $ips = $this->guard->resolveSafeIps($url);

        $options = [
            'allow_redirects' => false,
            'on_headers' => static function (ResponseInterface $response) use ($maxBytes): void {
                $length = $response->getHeaderLine('Content-Length');
                if ($length !== '' && ctype_digit($length) && (int) $length > $maxBytes) {
                    throw new RuntimeException('Response exceeds the maximum allowed size.');
                }
            },
            'progress' => static function ($downloadTotal, $downloaded) use ($maxBytes): void {
                if ($downloaded > $maxBytes) {
                    throw new RuntimeException('Response exceeds the maximum allowed size.');
                }
            },
        ];

        $pin = $this->curlResolveEntry($url, $ips);
        if ($pin !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$pin]];
        }

        return Http::timeout($timeoutSeconds)->withOptions($options);
    }

    /**
     * Build a CURLOPT_RESOLVE entry ("host:port:ip[,ip]") so curl connects only
     * to the addresses the guard approved instead of resolving DNS again.
     *
     * @param  list<string>  $ips
     */
    private function curlResolveEntry(string $url, array $ips): ?string
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';

        if ($ips === [] || $host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $addresses = array_map(
            static fn (string $ip): string => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '['.$ip.']' : $ip,
            $ips,
        );

        return $host.':'.$port.':'.implode(',', $addresses);
    }

    /**
     * Fallback size check for handlers that skip the streaming callbacks.
     */
    private function assertWithinSize(Response $response, string $url, int $maxBytes): void
    {
        if (strlen($response->body()) > $maxBytes) {
            throw SsrfProtectionException::blocked($url, 'Response exceeds the maximum allowed size.');
        }
    }
}
