<?php

declare(strict_types=1);

namespace App\Support\Http;

/**
 * Checks that a URL a user typed in points at a public HTTPS server before
 * Coqui fetches it, so a connector can't be aimed at the platform's own
 * network (Postgres, Redis, cloud metadata endpoints, other containers).
 *
 * Requests should pin the resolved IP returned by check() (see curlOptions)
 * so a DNS change between the check and the request can't redirect them.
 */
class PublicUrlGuard
{
    /**
     * @return array{base: string, host: string, port: int, ip: string}
     *
     * @throws UnsafeUrlException
     */
    public function check(string $url): array
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrlException('Enter the full address, starting with https://.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new UnsafeUrlException('The address must use https://.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('The address can\'t contain a username or password.');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new UnsafeUrlException('Enter the base address without ? or # parts.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? 443;

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);

        if ($ips === []) {
            throw new UnsafeUrlException("{$host} doesn't resolve to an address.");
        }

        foreach ($ips as $ip) {
            if (! $this->isPublic($ip)) {
                throw new UnsafeUrlException("{$host} points to a private or reserved address, which Coqui can't connect to. Use its public address.");
            }
        }

        $path = rtrim($parts['path'] ?? '', '/');
        $base = 'https://'.(str_contains($host, ':') ? "[{$host}]" : $host).($port !== 443 ? ":{$port}" : '').$path;

        return ['base' => $base, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /**
     * Guzzle options that pin a checked target to its checked IP and refuse
     * redirects (a redirect could point somewhere that wasn't checked).
     *
     * @param  array{base: string, host: string, port: int, ip: string}  $target
     * @return array<string, mixed>
     */
    public function curlOptions(array $target): array
    {
        $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];

        return [
            'allow_redirects' => false,
            'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]],
        ];
    }

    public function isPublic(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false
            // Ranges PHP's flags don't cover: carrier-grade NAT and the
            // IPv6 unique-local, link-local and IPv4-mapped blocks.
            && ! $this->inCidr($ip, '100.64.0.0/10')
            && ! preg_match('/^(fc|fd|fe8|fe9|fea|feb)/i', $ip)
            && ! str_starts_with(strtolower($ip), '::ffff:');
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        $ips = [];

        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = @dns_get_record($host, $type);
            foreach (is_array($records) ? $records : [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip)) {
                    $ips[] = $ip;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
