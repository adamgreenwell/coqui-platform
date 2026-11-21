<?php

declare(strict_types=1);

namespace App\Domains\Billing;

/**
 * The single source of truth for what each plan sells and what it may spend.
 *
 * The workspace limits, the billing page and the DataForSEO spend guard all
 * read from here. Before this existed the numbers lived in two places —
 * Workspace::applyPlanTier() and StripeBillingService::plans() — and drifted:
 * the Free plan ended up with a $25 data allowance against the $19 plan's $8,
 * so the free tier was more expensive than a paid one.
 *
 * Keep plan definitions here and nowhere else.
 */
final class PlanCatalog
{
    public const FREE = 'free';

    public const PERSONAL = 'personal';

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
    public const TIERS = [self::FREE, self::PERSONAL, self::PRO, self::AGENCY];

    /**
     * Share of the list price a workspace may spend on vendor data in a
     * month. The remainder has to cover hosting, payment fees, support and
     * margin.
     */
    public const ALLOWANCE_RATIO = 0.30;

    /**
     * A free workspace still gets enough data to evaluate the product, but
     * not enough to matter if a signup farms accounts.
     */
    public const MIN_ALLOWANCE_USD = 0.50;

    /**
     * @return array<string, array{
     *     name: string,
     *     price_usd: int,
     *     tracked_keyword_limit: int,
     *     cloud_crawl_url_limit: int,
     *     monthly_dfs_budget_usd: float,
     *     features: list<string>
     * }>
     */
    public static function all(): array
    {
        return [
            self::FREE => self::plan(
                name: 'Free / Desktop Sync',
                priceUsd: 0,
                keywordLimit: 100,
                crawlUrlLimit: 1000,
                features: [
                    'Desktop Crawl Snapshot Sync (Rust engine)',
                    '100 Tracked Keywords & Visibility Scoring',
                    '1,000 Cloud Crawl URLs per audit',
                    self::allowanceFeature(0),
                    'Basic Technical Issue Inspection',
                    'Google Search Console & Bing Performance Overlay',
                    'GA4, Umami & Plausible Organic & AI Traffic Attribution',
                ],
            ),
            self::PERSONAL => self::plan(
                name: 'Personal',
                priceUsd: 19,
                keywordLimit: 300,
                crawlUrlLimit: 5000,
                features: [
                    'Everything in Free',
                    '300 Tracked Keywords with Daily Updates',
                    '5,000 Cloud Crawl URLs per audit',
                    self::allowanceFeature(19),
                    'Live SERP Top-10 Content Brief Generator',
                    'Backlink Profile Explorer & 404 Recovery',
                    'Bot & AI Crawler Telemetry',
                ],
            ),
            self::PRO => self::plan(
                name: 'Pro Cloud',
                priceUsd: 49,
                keywordLimit: 1000,
                crawlUrlLimit: 25000,
                features: [
                    'Everything in Free',
                    '1,000 Tracked Keywords with Daily Updates',
                    '25,000 Cloud Crawl URLs per audit',
                    self::allowanceFeature(49),
                    'Live SERP Top-10 Content Brief Generator',
                    'Keyword Gap & Dual-Competitor Matrix',
                    'Backlink Profile Explorer & 404 Recovery',
                ],
            ),
            self::AGENCY => self::plan(
                name: 'Agency & Enterprise',
                priceUsd: 199,
                keywordLimit: 10000,
                crawlUrlLimit: 250000,
                features: [
                    'Everything in Pro',
                    '10,000 Tracked Keywords with Hourly Alerts',
                    '250,000 Cloud Crawl URLs per audit',
                    self::allowanceFeature(199),
                    'Unlimited Workspace Team Members',
                    'White-Label Client Crawl Diff Reports',
                    'Priority Cloud Queue & Worker Allocation',
                    'Native Runnr Deploy Smoke Tests & Bot Telemetry Included',
                ],
            ),
            self::RUNNR_ADDON => self::plan(
                name: 'Runnr Hosting Add-on',
                priceUsd: 29,
                keywordLimit: 0,
                crawlUrlLimit: 0,
                features: [
                    'Automated Post-Deploy SEO Smoke Testing',
                    'Bot & AI Crawler Telemetry Ingestion (Googlebot, GPTBot, ClaudeBot, PerplexityBot)',
                    'Automated 301 Redirect Remediation for Dead Bot Hits',
                    'Embeddable Live Smoke Badge for GitHub CI/CD',
                ],
                // The add-on sells hosting features, not data.
                allowanceUsd: 0.0,
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
     *     features: list<string>
     * }
     */
    public static function get(string $tier): array
    {
        $plans = self::all();
        $key = strtolower(trim($tier));

        return $plans[$key] ?? $plans[self::FREE];
    }

    /**
     * Monthly vendor-data allowance for a plan price, rounded up to a whole
     * dollar so the pricing page and the spend guard show the same number.
     */
    public static function allowanceFor(int $priceUsd): float
    {
        if ($priceUsd <= 0) {
            return self::MIN_ALLOWANCE_USD;
        }

        return (float) max(1, (int) ceil($priceUsd * self::ALLOWANCE_RATIO));
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
     * The allowance line on the pricing page, derived from the same number
     * the spend guard enforces.
     */
    public static function allowanceFeature(int $priceUsd): string
    {
        return '$'.self::money(self::allowanceFor($priceUsd)).'/mo Live SEO Data Allowance';
    }

    /**
     * @param  list<string>  $features
     * @return array{
     *     name: string,
     *     price_usd: int,
     *     tracked_keyword_limit: int,
     *     cloud_crawl_url_limit: int,
     *     monthly_dfs_budget_usd: float,
     *     features: list<string>
     * }
     */
    private static function plan(
        string $name,
        int $priceUsd,
        int $keywordLimit,
        int $crawlUrlLimit,
        array $features,
        ?float $allowanceUsd = null,
    ): array {
        return [
            'name' => $name,
            'price_usd' => $priceUsd,
            'tracked_keyword_limit' => $keywordLimit,
            'cloud_crawl_url_limit' => $crawlUrlLimit,
            'monthly_dfs_budget_usd' => $allowanceUsd ?? self::allowanceFor($priceUsd),
            'features' => $features,
        ];
    }
}
