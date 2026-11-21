<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One way to identify a page across crawls, search performance and traffic
 * analytics: its path, without query string, fragment or trailing slash.
 * "https://example.com/features/?utm=1" and "/features" are the same page.
 */
final class PageUrl
{
    public static function normalizePath(string $urlOrPath): string
    {
        $raw = trim($urlOrPath);
        if ($raw === '') {
            return '/';
        }

        $parsedPath = parse_url($raw, PHP_URL_PATH);
        $path = is_string($parsedPath) ? $parsedPath : $raw;

        // Strip query parameters and anchors if raw was just "/path?foo=1".
        $path = explode('?', $path)[0];
        $path = explode('#', $path)[0];
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return '/';
        }

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    /**
     * SHA-256 of the normalized path. search_performance_pages.url_hash uses
     * this, so page rows join to crawl URLs and traffic rows by path.
     */
    public static function hash(string $urlOrPath): string
    {
        return hash('sha256', self::normalizePath($urlOrPath));
    }
}
