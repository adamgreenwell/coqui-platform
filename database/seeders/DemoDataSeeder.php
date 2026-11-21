<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Backlinks\Models\BacklinkHistorySnapshot;
use App\Domains\Backlinks\Models\MonitoredBacklink;
use App\Domains\Billing\Services\StripeBillingService;
use App\Domains\Crawls\Models\CrawlIssue;
use App\Domains\Crawls\Models\CrawlRun;
use App\Domains\Crawls\Models\CrawlUrl;
use App\Domains\Integrations\Models\BotCrawlLog;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectCompetitor;
use App\Domains\Projects\Models\Workspace;
use App\Domains\Serp\Models\KeywordRankSnapshot;
use App\Domains\Serp\Models\TrackedKeyword;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local demo data: a login, a workspace on the Pro plan and one project with
 * a synced crawl, rank history, backlink history, a link watchlist and bot
 * telemetry, so every page renders without provider credentials.
 *
 * The login comes from DEMO_USER_EMAIL / DEMO_USER_PASSWORD.
 */
class DemoDataSeeder extends Seeder
{
    public const WORKSPACE_SLUG = 'demo-workspace';

    public const PROJECT_DOMAIN = 'demo-roastery.test';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The demo seeder only runs outside production (APP_ENV=production).');
        }

        $email = (string) config('coqui.demo.email');
        $password = (string) config('coqui.demo.password');

        if (Workspace::query()->where('slug', self::WORKSPACE_SLUG)->exists()) {
            $this->command?->info("Demo data already exists. Sign in as {$email}, or run `php artisan migrate:fresh --seed` to rebuild it.");

            return;
        }

        // Same random walk on every run.
        mt_srand(56);

        DB::transaction(function () use ($email, $password): void {
            $user = $this->seedUser($email, $password);
            $workspace = $this->seedWorkspace($user);
            $project = $this->seedProject($workspace);

            $this->seedCrawls($project);
            $this->seedKeywords($project);
            $this->seedBacklinks($project);
            $this->seedBotTelemetry($project);
        });

        $this->command?->info("Demo data seeded. Sign in as {$email}.");
    }

    private function seedUser(string $email, string $password): User
    {
        /** @var User $user */
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => 'Demo User',
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    private function seedWorkspace(User $user): Workspace
    {
        $plan = app(StripeBillingService::class)->getPlans()['pro'];

        /** @var Workspace $workspace */
        $workspace = Workspace::query()->create([
            'name' => 'Demo Workspace',
            'slug' => self::WORKSPACE_SLUG,
            'owner_id' => $user->id,
            'plan_tier' => 'pro',
            'tracked_keyword_limit' => $plan['tracked_keyword_limit'],
            'cloud_crawl_url_limit' => $plan['cloud_crawl_url_limit'],
            'monthly_dfs_budget_usd' => $plan['monthly_dfs_budget_usd'],
        ]);
        $workspace->members()->attach($user->id, ['role' => 'owner']);

        return $workspace;
    }

    private function seedProject(Workspace $workspace): Project
    {
        /** @var Project $project */
        $project = Project::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Demo Roastery',
            'domain' => self::PROJECT_DOMAIN,
            'canonical_url' => 'https://'.self::PROJECT_DOMAIN.'/',
            'location_code' => 2840,
            'language_code' => 'en',
            'crawl_cadence' => 'weekly',
            'last_crawled_at' => now()->subDay(),
        ]);

        foreach ([['rival-roasters.test', 'Rival Roasters'], ['bean-collective.test', 'Bean Collective']] as $order => [$domain, $label]) {
            ProjectCompetitor::query()->create([
                'project_id' => $project->id,
                'domain' => $domain,
                'label' => $label,
                'sort_order' => $order,
            ]);
        }

        return $project;
    }

    /**
     * Two synced desktop crawls a week apart, so the latest-crawl card and
     * crawl comparison both have something to show.
     */
    private function seedCrawls(Project $project): void
    {
        $base = 'https://'.self::PROJECT_DOMAIN;
        $pages = [
            ['/', 'Demo Roastery | Small-Batch Coffee', 200, 820],
            ['/shop', 'Shop Coffee Beans', 200, 540],
            ['/shop/ethiopia-yirgacheffe', 'Ethiopia Yirgacheffe', 200, 610],
            ['/shop/colombia-huila', 'Colombia Huila', 200, 580],
            ['/shop/decaf-house-blend', '', 200, 190],
            ['/brew-guides', 'Brew Guides', 200, 450],
            ['/brew-guides/pour-over', 'How to Make Pour-Over Coffee', 200, 1640],
            ['/brew-guides/french-press', 'French Press Guide', 200, 1210],
            ['/subscriptions', 'Coffee Subscriptions', 200, 700],
            ['/about', 'About Demo Roastery', 200, 380],
            ['/wholesale', 'Wholesale', 301, null],
            ['/old-blog/cold-brew', 'Not Found', 404, null],
        ];

        foreach ([[8, false], [1, true]] as [$daysAgo, $latest]) {
            $finishedAt = now()->subDays($daysAgo);
            $issues = $latest
                ? [
                    ['/shop/decaf-house-blend', 'missing-title', 'error', 'The page has no <title>.'],
                    ['/shop/decaf-house-blend', 'thin-content', 'warning', 'Only 190 words on the page.'],
                    ['/old-blog/cold-brew', 'broken-link', 'error', 'Linked from /brew-guides with a 404 response.'],
                    ['/wholesale', 'redirect-chain', 'warning', 'Redirects twice before reaching /contact.'],
                    ['/brew-guides/french-press', 'missing-meta-description', 'warning', 'No meta description.'],
                    ['/about', 'missing-h1', 'warning', 'The page has no H1.'],
                ]
                : [
                    ['/shop/decaf-house-blend', 'missing-title', 'error', 'The page has no <title>.'],
                    ['/old-blog/cold-brew', 'broken-link', 'error', 'Linked from /brew-guides with a 404 response.'],
                    ['/brew-guides/french-press', 'missing-meta-description', 'warning', 'No meta description.'],
                    ['/shop/colombia-huila', 'missing-meta-description', 'warning', 'No meta description.'],
                ];

            /** @var CrawlRun $run */
            $run = CrawlRun::query()->create([
                'project_id' => $project->id,
                'external_crawl_id' => (string) Str::uuid(),
                'source' => 'desktop',
                'seed' => $base.'/',
                'seed_kind' => 'url',
                'status' => 'completed',
                'config' => ['maxDepth' => 5, 'respectRobots' => true],
                'total_urls' => count($pages),
                'error_count' => count(array_filter($issues, fn (array $i): bool => $i[2] === 'error')),
                'warning_count' => count(array_filter($issues, fn (array $i): bool => $i[2] === 'warning')),
                'opportunity_count' => 0,
                'client_created_at_ms' => $finishedAt->copy()->subMinutes(4)->getTimestampMs(),
                'client_started_at_ms' => $finishedAt->copy()->subMinutes(4)->getTimestampMs(),
                'client_finished_at_ms' => $finishedAt->getTimestampMs(),
                'finalized_at' => $finishedAt,
            ]);

            foreach ($pages as $row => [$path, $title, $status, $words]) {
                $url = $base.$path;
                CrawlUrl::query()->create([
                    'crawl_run_id' => $run->id,
                    'client_row_id' => $row + 1,
                    'url' => $url,
                    'url_hash' => hash('sha256', $url),
                    'status' => $status,
                    'content_type' => 'text/html',
                    'title' => $title !== '' ? $title : null,
                    'meta_description' => $status === 200 && $path !== '/brew-guides/french-press' ? "{$title} from Demo Roastery." : null,
                    'h1' => $status === 200 && $path !== '/about' ? ($title !== '' ? $title : 'Decaf House Blend') : null,
                    'h1_count' => $status === 200 && $path !== '/about' ? 1 : 0,
                    'canonical' => $status === 200 ? $url : null,
                    'redirect_target' => $status === 301 ? $base.'/contact' : null,
                    'redirect_chain' => $status === 301 ? [$base.'/wholesale-enquiries', $base.'/contact'] : [],
                    'word_count' => $words,
                    'depth' => $path === '/' ? 0 : substr_count(trim($path, '/'), '/') + 1,
                    'response_time_ms' => 120 + mt_rand(0, 380),
                    'size_bytes' => 18_000 + mt_rand(0, 40_000),
                    'discovered_at_ms' => $finishedAt->copy()->subMinutes(3)->getTimestampMs(),
                ]);
            }

            foreach ($issues as [$path, $rule, $severity, $detail]) {
                CrawlIssue::query()->create([
                    'crawl_run_id' => $run->id,
                    'url' => $base.$path,
                    'url_hash' => hash('sha256', $base.$path),
                    'rule' => $rule,
                    'severity' => $severity,
                    'detail' => $detail,
                ]);
            }
        }
    }

    /**
     * Tracked keywords with 30 days of daily rank history.
     */
    private function seedKeywords(Project $project): void
    {
        $keywords = [
            // keyword => [volume, difficulty, starting rank or null]
            'small batch coffee beans' => [2400, 38, 14],
            'ethiopian yirgacheffe coffee' => [1900, 31, 6],
            'coffee subscription' => [33100, 72, 41],
            'how to make pour over coffee' => [8100, 44, 9],
            'french press ratio' => [6600, 29, 3],
            'colombian coffee beans' => [5400, 47, 22],
            'decaf coffee beans' => [4400, 41, null],
            'local coffee roaster' => [1300, 25, 1],
        ];

        foreach ($keywords as $keyword => [$volume, $difficulty, $rank]) {
            /** @var TrackedKeyword $tracked */
            $tracked = TrackedKeyword::query()->create([
                'project_id' => $project->id,
                'keyword' => $keyword,
                'device' => 'desktop',
                'location_code' => 2840,
                'language_code' => 'en',
                'cadence' => 'daily',
                'search_volume' => $volume,
                'keyword_difficulty' => $difficulty,
                'is_active' => true,
                'last_checked_at' => now()->startOfDay()->addHours(6),
            ]);

            for ($daysAgo = 29; $daysAgo >= 0; $daysAgo--) {
                if ($rank !== null) {
                    // Drift slowly, mostly upwards.
                    $rank = max(1, min(100, $rank + mt_rand(-2, 1)));
                }

                KeywordRankSnapshot::query()->create([
                    'tracked_keyword_id' => $tracked->id,
                    'project_id' => $project->id,
                    'domain' => self::PROJECT_DOMAIN,
                    'rank_group' => $rank,
                    'rank_absolute' => $rank !== null ? $rank + ($rank > 3 ? 1 : 0) : null,
                    'ranking_url' => $rank !== null ? 'https://'.self::PROJECT_DOMAIN.'/'.Str::slug($keyword) : null,
                    'ranking_title' => $rank !== null ? Str::title($keyword) : null,
                    'has_ai_overview' => $volume > 5000,
                    'has_featured_snippet' => str_starts_with($keyword, 'how to'),
                    'serp_features' => $volume > 5000 ? ['ai_overview', 'people_also_ask'] : ['people_also_ask'],
                    'checked_on' => now()->subDays($daysAgo)->toDateString(),
                ]);
            }
        }
    }

    /**
     * 90 days of backlink history and a small link watchlist.
     */
    private function seedBacklinks(Project $project): void
    {
        $backlinks = 1180;
        $domains = 212;

        for ($daysAgo = 89; $daysAgo >= 0; $daysAgo--) {
            $newLinks = mt_rand(2, 14);
            $lostLinks = mt_rand(0, 9);
            $newDomains = mt_rand(0, 3);
            $lostDomains = mt_rand(0, 2);
            $backlinks += $newLinks - $lostLinks;
            $domains += $newDomains - $lostDomains;

            BacklinkHistorySnapshot::query()->create([
                'project_id' => $project->id,
                'snapshot_date' => now()->subDays($daysAgo)->toDateString(),
                'backlinks_count' => $backlinks,
                'referring_domains_count' => $domains,
                'new_backlinks' => $newLinks,
                'lost_backlinks' => $lostLinks,
                'new_referring_domains' => $newDomains,
                'lost_referring_domains' => $lostDomains,
                'rank' => 180 + intdiv(89 - $daysAgo, 10),
            ]);
        }

        $target = 'https://'.self::PROJECT_DOMAIN.'/';
        $watchlist = [
            ['https://coffee-blog.test/best-roasters-2026', 'Demo Roastery', 'dofollow', 'active', 200, 'dofollow', false],
            ['https://brew-forum.test/threads/pour-over-tips', 'pour-over guide', 'dofollow', 'active', 200, 'dofollow', false],
            ['https://city-guide.test/coffee-shops', 'Demo Roastery', 'dofollow', 'nofollow', 200, 'nofollow', false],
            ['https://old-directory.test/listing/demo-roastery', 'Demo Roastery', 'dofollow', 'lost', 404, null, false],
            ['https://partner-cafe.test/our-beans', 'our beans', 'dofollow', 'noindex', 200, 'dofollow', true],
        ];

        foreach ($watchlist as $i => [$source, $anchor, $expectedRel, $state, $status, $observedRel, $noindex]) {
            MonitoredBacklink::query()->create([
                'project_id' => $project->id,
                'source_url' => $source,
                'source_url_hash' => hash('sha256', $source),
                'target_url' => $target,
                'expected_anchor' => $anchor,
                'expected_rel' => $expectedRel,
                'state' => $state,
                'last_http_status' => $status,
                'last_observed_rel' => $observedRel,
                'last_page_noindex' => $noindex,
                'last_verified_at' => now()->subHours(3 + $i),
            ]);
        }
    }

    /**
     * A week of hourly search and AI crawler hits.
     */
    private function seedBotTelemetry(Project $project): void
    {
        $bots = ['googlebot' => 40, 'bingbot' => 12, 'gptbot' => 9, 'claudebot' => 6, 'perplexitybot' => 4];
        $start = Carbon::now()->startOfHour()->subDays(7);

        for ($hour = 0; $hour < 7 * 24; $hour += 3) {
            $bucket = $start->copy()->addHours($hour);
            foreach ($bots as $bot => $typical) {
                BotCrawlLog::query()->create([
                    'project_id' => $project->id,
                    'bot_family' => $bot,
                    'status_bucket' => 200,
                    'hit_count' => max(1, $typical + mt_rand(-$typical >> 1, $typical >> 1)),
                    'bytes_served' => $typical * 42_000,
                    'top_error_paths' => [],
                    'bucket_hour' => $bucket,
                ]);
            }

            if ($hour % 12 === 0) {
                BotCrawlLog::query()->create([
                    'project_id' => $project->id,
                    'bot_family' => 'googlebot',
                    'status_bucket' => 400,
                    'hit_count' => 3,
                    'bytes_served' => 6_000,
                    'top_error_paths' => [['path' => '/old-blog/cold-brew', 'hits' => 3]],
                    'bucket_hour' => $bucket,
                ]);
            }
        }
    }
}
