<?php

declare(strict_types=1);

namespace App\Domains\Billing;

/**
 * The single source of truth for what each plan sells and what it may spend.
 *
 * The workspace limits, the billing page and the DataForSEO spend guard all
 * read from here. Before this existed the numbers lived in two places and
 * drifted: the Free plan ended up with a $25 data allowance against the $19
 * plan's $8, so the free tier was more expensive than a paid one.
 *
 * Two currencies, deliberately:
 * - a hard dollar allowance (the spend guard's ceiling), and
 * - SERP credits, one per page of 10 results, so depth is a price rather
 *   than a permission: anyone may request depth 100, it just costs 10
 *   credits instead of 1.
 *
 * The desktop crawler is not metered anywhere. It runs on the customer's
 * machine, costs us nothing, and gating it would undo the reason people
 * switch from Screaming Frog.
 */
final class PlanCatalog
{
    public const FREE = 'free';

    public const SOLO = 'solo';

    public const PRO = 'pro';

    public const AGENCY = 'agency';

    public const RUNNR_ADDON = 'runnr_addon';

    /**
     * Base plans a workspace can pay for, cheapest first. Free is the
     * absence of a subscription; the Runnr add-on is billed on top of any
     * of them.
     *
     * @var list<string>
     */
    public const TIERS = [self::FREE, self::SOLO, self::PRO, self::AGENCY];

    /**
     * Share of the list price a workspace may spend on vendor data in a
     * month. The remainder has to cover hosting, payment fees, support and
     * margin.
     */
    public const ALLOWANCE_RATIO = 0.30;

    /**
     * A free workspace still gets enough data to evaluate the product, but
     * not enough to matter if a signup farms accounts. $0.50 is roughly 20
     * backlink lookups or 800 rank-check pages.
     */
    public const MIN_ALLOWANCE_USD = 0.50;

    /** One credit buys one page of 10 SERP results. */
    public const RESULTS_PER_CREDIT = 10;

    /**
     * @return array<string, array{
     *     name: string,
     *     price_usd: int,
     *     tracked_keyword_limit: int,
     *     cloud_crawl_url_limit: int,
     *     monthly_dfs_budget_usd: float,
     *     monthly_serp_credits: int,
     *     max_import_bytes: int,
     *     monthly_content_briefs: int,
     *     monthly_llm_prompts: int,
     *     features: list<string>
     * }>
     */
    public static function all(): array
    {
        return [
            self::FREE => self::plan(
                name: 'Free',
                priceUsd: 0,
                keywordLimit: 20,
                crawlUrlLimit: 1000,
                credits: 100,
                maxImportBytes: 25 * 1024 * 1024,
                briefs: 0,
                prompts: 0,
                allowanceUsd: self::MIN_ALLOWANCE_USD,
                features: [
                    'Coqui Desktop: unlimited local crawling, including JS rendering and the full audit rule set',
                    'Screaming Frog database import',
                    'CSV, XLSX, HTML and PDF exports',
                    'Google Search Console, Bing, GA4, Plausible and Umami overlays',
                    'Top 3 things to fix this week',
                    '100 SERP credits (~23 keywords weekly at depth 10)',
                ],
            ),
            self::SOLO => self::plan(
                name: 'Solo',
                priceUsd: 29,
                keywordLimit: 300,
                crawlUrlLimit: 5000,
                credits: 3000,
                maxImportBytes: 100 * 1024 * 1024,
                briefs: 10,
                prompts: 0,
                allowanceUsd: 8.00,
                features: [
                    'Everything in Free',
                    'Cloud crawl history and cross-crawl diffing',
                    'Scheduled local crawls and saved crawl profiles',
                    'Full action cards and weekly priorities',
                    '3,000 SERP credits (~690 keywords weekly at depth 10)',
                    '10 content briefs a month',
                ],
            ),
            self::PRO => self::plan(
                name: 'Pro',
                priceUsd: 79,
                keywordLimit: 1000,
                crawlUrlLimit: 25000,
                credits: 10000,
                maxImportBytes: 500 * 1024 * 1024,
                briefs: 50,
                prompts: 25,
                allowanceUsd: 22.00,
                features: [
                    'Everything in Solo',
                    'Keyword gap and link intersect',
                    'Public MCP server and REST API',
                    'Bot and AI-crawler telemetry',
                    '10,000 SERP credits (~2,300 keywords weekly at depth 10)',
                    '50 content briefs and 25 AI/GEO prompts a month',
                ],
            ),
            self::AGENCY => self::plan(
                name: 'Agency & Enterprise',
                priceUsd: 199,
                keywordLimit: 5000,
                crawlUrlLimit: 250000,
                credits: 45000,
                maxImportBytes: 2 * 1024 * 1024 * 1024,
                briefs: 200,
                prompts: 100,
                allowanceUsd: 58.00,
                features: [
                    'Everything in Pro',
                    'White-label PDF and HTML reports',
                    'Custom-domain share links',
                    'Runnr deploy smoke tests and bot telemetry included',
                    '45,000 SERP credits (~10,400 keywords weekly at depth 10)',
                    '200 content briefs and 100 AI/GEO prompts a month',
                ],
            ),
            self::RUNNR_ADDON => self::plan(
                name: 'Runnr Hosting Add-on',
                priceUsd: 29,
                keywordLimit: 0,
                crawlUrlLimit: 0,
                credits: 0,
                maxImportBytes: 0,
                briefs: 0,
                prompts: 0,
                // The add-on sells hosting features, not data.
                allowanceUsd: 0.0,
                features: [
                    'Automated Post-Deploy SEO Smoke Testing',
                    'Bot & AI Crawler Telemetry Ingestion (Googlebot, GPTBot, ClaudeBot, PerplexityBot)',
                    'Automated 301 Redirect Remediation for Dead Bot Hits',
                    'Embeddable Live Smoke Badge for GitHub CI/CD',
                ],
            ),
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     price_usd: int,
     *     tracked_keyword_limit: int,
     *     cloud_crawl_url_limit: int,
     *     monthly_dfs_budget_usd: float,
     *     monthly_serp_credits: int,
     *     max_import_bytes: int,
     *     monthly_content_briefs: int,
     *     monthly_llm_prompts: int,
     *     features: list<string>
     * }
     */
    public static function get(?string $tier): array
    {
        $plans = self::all();
        $key = strtolower(trim((string) $tier));

        return $plans[$key] ?? $plans[self::FREE];
    }

    /**
     * Monthly vendor-data allowance for a plan price, rounded up to a whole
     * dollar so the pricing page and the spend guard show the same number.
     * Plans with an explicit allowance keep it, so the published cap is the
     * one that gets enforced.
     */
    public static function allowanceFor(int $priceUsd): float
    {
        if ($priceUsd <= 0) {
            return self::MIN_ALLOWANCE_USD;
        }

        return (float) max(1, (int) ceil($priceUsd * self::ALLOWANCE_RATIO));
    }

    /**
     * Credits a check consumes: one per page of 10 results, so depth 100 is
     * ten credits.
     */
    public static function creditsForDepth(int $depth): int
    {
        return max(1, (int) ceil($depth / self::RESULTS_PER_CREDIT));
    }

    /**
     * Format a dollar amount for customer-facing copy: whole dollars stay
     * whole ("$15"), cents are shown when present ("$0.50").
     */
    public static function money(float $usd): string
    {
        return $usd === floor($usd)
            ? number_format($usd, 0)
            : number_format($usd, 2);
    }

    /**
     * @param  list<string>  $features
     * @return array{
     *     name: string,
     *     price_usd: int,
     *     tracked_keyword_limit: int,
     *     cloud_crawl_url_limit: int,
     *     monthly_dfs_budget_usd: float,
     *     monthly_serp_credits: int,
     *     max_import_bytes: int,
     *     monthly_content_briefs: int,
     *     monthly_llm_prompts: int,
     *     features: list<string>
     * }
     */
    private static function plan(
        string $name,
        int $priceUsd,
        int $keywordLimit,
        int $crawlUrlLimit,
        int $credits,
        int $maxImportBytes,
        int $briefs,
        int $prompts,
        float $allowanceUsd,
        array $features,
    ): array {
        return [
            'name' => $name,
            'price_usd' => $priceUsd,
            'tracked_keyword_limit' => $keywordLimit,
            'cloud_crawl_url_limit' => $crawlUrlLimit,
            'monthly_dfs_budget_usd' => $allowanceUsd,
            'monthly_serp_credits' => $credits,
            'max_import_bytes' => $maxImportBytes,
            'monthly_content_briefs' => $briefs,
            'monthly_llm_prompts' => $prompts,
            'features' => $features,
        ];
    }
}
