<?php

declare(strict_types=1);

namespace App\Domains\Crawls\Services;

use App\Domains\Crawls\Exceptions\SsrfProtectionException;

class SsrfProtectionGuard
{
    /** @var list<string> */
    private const array FORBIDDEN_HOSTNAMES = [
        'localhost',
        'metadata.google.internal',
        'instance-data',
    ];

    /**
     * Assert that the given URL does not target a private, loopback, or metadata IP.
     *
     * @throws SsrfProtectionException
     */
    public function assertAllowed(string $url): void
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw SsrfProtectionException::blocked($url, 'Malformed URL or missing scheme/host.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw SsrfProtectionException::blocked($url, sprintf('Forbidden scheme "%s". Only HTTP/HTTPS allowed.', $scheme));
        }

        $host = strtolower((string) $parts['host']);

        // Check explicit forbidden hostnames
        foreach (self::FORBIDDEN_HOSTNAMES as $forbidden) {
            if ($host === $forbidden || str_ends_with($host, '.'.$forbidden)) {
                throw SsrfProtectionException::blocked($url, sprintf('Forbidden hostname "%s".', $host));
            }
        }

        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.lan')) {
            throw SsrfProtectionException::blocked($url, sprintf('Private internal domain "%s".', $host));
        }

        // Check if host is direct IP address
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertSafeIp($host, $url);

            return;
        }

        // Resolve host to IPs
        $resolvedIps = $this->resolveHost($host);
        if ($resolvedIps === []) {
            // Unresolvable hostname
            throw SsrfProtectionException::blocked($url, sprintf('Could not resolve hostname "%s".', $host));
        }

        foreach ($resolvedIps as $ip) {
            $this->assertSafeIp($ip, $url);
        }
    }

    /**
     * Check if a URL is safe without throwing.
     */
    public function isAllowed(string $url): bool
    {
        try {
            $this->assertAllowed($url);

            return true;
        } catch (SsrfProtectionException) {
            return false;
        }
    }

    /**
     * Verify that an IP address is not in a private, loopback, or cloud-metadata range.
     *
     * @throws SsrfProtectionException
     */
    public function assertSafeIp(string $ip, string $originalUrl): void
    {
        // 1. Standard PHP filter check for private and reserved ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw SsrfProtectionException::blocked($originalUrl, sprintf('Resolved to private or reserved IP %s.', $ip));
        }

        // 2. IPv4 Specific Range Checks
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                throw SsrfProtectionException::blocked($originalUrl, sprintf('Invalid IPv4 address %s.', $ip));
            }

            // Loopback: 127.0.0.0/8
            if (($long & 0xFF000000) === 0x7F000000) {
                throw SsrfProtectionException::blocked($originalUrl, sprintf('Loopback IP %s is forbidden.', $ip));
            }

            // Zero network: 0.0.0.0/8
            if (($long & 0xFF000000) === 0x00000000) {
                throw SsrfProtectionException::blocked($originalUrl, sprintf('Current network IP %s is forbidden.', $ip));
            }

            // Link-local / AWS / GCP / Azure metadata: 169.254.0.0/16
            if (($long & 0xFFFF0000) === 0xA9FE0000) {
                throw SsrfProtectionException::blocked($originalUrl, sprintf('Link-local cloud metadata IP %s is forbidden.', $ip));
            }

            // RFC 1918 10.0.0.0/8
            if (($long & 0xFF000000) === 0x0A000000) {
                throw SsrfProtectionException::blocked($originalUrl, sprintf('RFC1918 private IP %s is forbidden.', $ip));
            }

            // RFC 1918 172.16.0.0/12
            if (($long & 0xFFF00000) === 0xAC100000) {
                throw SsrfProtectionException::blocked($originalUrl, sprintf('RFC1918 private IP %s is forbidden.', $ip));
            }

            // RFC 1918 192.168.0.0/16
            if (($long & 0xFFFF0000) === 0xC0A80000) {
                throw SsrfProtectionException::blocked($originalUrl, sprintf('RFC1918 private IP %s is forbidden.', $ip));
            }

            // Carrier-grade NAT: 100.64.0.0/10
            if (($long & 0xFFC00000) === 0x64400000) {
                throw SsrfProtectionException::blocked($originalUrl, sprintf('Carrier-grade NAT IP %s is forbidden.', $ip));
            }
        }

        // 3. IPv6 Specific Checks
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            // IPv6 loopback
            if ($lower === '::1' || $lower === '0:0:0:0:0:0:0:1') {
                throw SsrfProtectionException::blocked($originalUrl, sprintf('IPv6 loopback %s is forbidden.', $ip));
            }

            // IPv6 link-local fe80::/10
            if (str_starts_with($lower, 'fe8') || str_starts_with($lower, 'fe9') || str_starts_with($lower, 'fea') || str_starts_with($lower, 'feb')) {
                throw SsrfProtectionException::blocked($originalUrl, sprintf('IPv6 link-local %s is forbidden.', $ip));
            }

            // IPv4-mapped IPv6 ::ffff:127.0.0.1
            if (str_starts_with($lower, '::ffff:')) {
                $v4part = substr($lower, 7);
                if (filter_var($v4part, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $this->assertSafeIp($v4part, $originalUrl);
                }
            }
        }
    }

    /**
     * Resolve hostname to IPv4/IPv6 addresses.
     *
     * @return list<string>
     */
    protected function resolveHost(string $host): array
    {
        /** @var list<string> $ips */
        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $fallback = @gethostbynamel($host);
            if (is_array($fallback)) {
                foreach ($fallback as $v4) {
                    if (is_string($v4)) {
                        $ips[] = $v4;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
