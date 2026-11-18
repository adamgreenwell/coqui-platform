// Coqui SEO Platform backlog - features across Phases 1-5.

export const features = [
  // ---------------- E01 Foundation ----------------
  {
    id: 'F-01',
    epic: 'E01',
    title: 'Laravel 13 & Coolify Docker Compose stack',
    area: 'coolify',
    phase: 1,
    size: 'L',
    priority: 'P0',
    summary:
      'Production-ready Laravel 13 skeleton running PHP 8.4-FPM + Nginx, PostgreSQL 17, Redis 7, Horizon, Reverb, and Scheduler as a unified Coolify Docker Compose stack.',
    scope: [
      'Multi-stage Dockerfile with PHP 8.4, pdo_pgsql, redis, pcntl, bcmath, gd, intl, and Node 22 asset build',
      'docker-compose.yml with app, nginx, horizon, reverb, scheduler, postgres (pg17), redis, and optional coqui-worker profile',
      'Laravel 13 bootstrap/app.php routing, /up health endpoint, and Horizon/Reverb service configuration',
      'GitHub Actions CI running Pint, PHPUnit, and roadmap build verification',
    ],
    acceptance: [
      'Stack boots cleanly under Docker Compose / Coolify with passing /up health check',
      'Horizon processes queued jobs on Redis and Reverb accepts WebSocket connections',
      'CI workflow validates formatting, PHPUnit tests, and plan artifact generation on every push',
    ],
    deps: [],
  },
  {
    id: 'F-02',
    epic: 'E01',
    title: 'Workspaces, RBAC, and Sanctum desktop auth',
    area: 'platform',
    phase: 1,
    size: 'M',
    priority: 'P0',
    summary:
      'Multi-tenant workspaces with role-based membership and Laravel Sanctum personal access tokens / device linking for the Coqui desktop application.',
    scope: [
      'Workspaces and workspace_memberships tables with owner, admin, editor, and viewer roles',
      'Fortify authentication + Inertia React workspace switcher shell',
      'Sanctum token issuance UI and scoped API abilities (crawls:sync, projects:read)',
      'Audit trail of workspace membership changes and active desktop tokens',
    ],
    acceptance: [
      'Users can belong to multiple workspaces and switch active context without cross-tenant data leakage',
      'A Sanctum token generated in the web UI authenticates Coqui Desktop API requests and can be revoked immediately',
      'Authorization policies enforce workspace ownership across all Project and Crawl endpoints',
    ],
    deps: ['F-01'],
  },
  {
    id: 'F-03',
    epic: 'E01',
    title: 'Project and competitor registry',
    area: 'platform',
    phase: 1,
    size: 'M',
    priority: 'P0',
    summary:
      'Core SEO Project domain representing a canonical root domain, target market/locale defaults, and up to 5 tracked competitor domains.',
    scope: [
      'projects and project_competitors migrations with normalized host extraction and unique constraints',
      'Default DataForSEO location_code (e.g. 2840 US) and language_code (e.g. en) per project',
      'Project dashboard overview card summarizing latest crawl health, tracked keyword visibility, and backlink baseline',
      'Project settings UI to manage canonical host, target locale, and competitor domains',
    ],
    acceptance: [
      'Creating a project normalizes protocol/trailing slashes to a canonical domain and host',
      'Users can add, reorder, and remove competitor domains with duplicate-domain validation',
      'Project deletion cascades cleanly or soft-deletes child runs without orphaned records',
    ],
    deps: ['F-02'],
  },
  {
    id: 'F-04',
    epic: 'E01',
    title: 'Reverb realtime event stream & activity log',
    area: 'platform',
    phase: 1,
    size: 'S',
    priority: 'P1',
    summary:
      'Broadcast channels for streaming live crawl progress, DataForSEO batch completions, and deploy smoke-test alerts to active project dashboards.',
    scope: [
      'Private workspace/project broadcast authorization in routes/channels.php',
      'Standardized event payloads for CrawlProgressUpdated, SerpBatchCompleted, and BacklinkAlertTriggered',
      'Persistent project activity feed recording syncs, scheduled runs, and failures',
    ],
    acceptance: [
      'Authorized workspace members subscribe to project.{id} over Reverb without exposing other tenants',
      'UI updates live when a background Horizon job completes a SERP batch or ingests a desktop crawl',
    ],
    deps: ['F-01', 'F-02'],
  },

  // ---------------- E02 DataForSEO Core ----------------
  {
    id: 'D-01',
    epic: 'E02',
    title: 'DataForSEO v3 HTTP client, sandbox toggle & archive',
    area: 'dataforseo',
    phase: 1,
    size: 'L',
    priority: 'P0',
    summary:
      'Resilient HTTP client for DataForSEO REST v3 supporting sandbox vs production switching, idempotent retries on free reads, and raw payload archival.',
    scope: [
      'config/coqui.php configuration for login, password, base_url (defaulting to sandbox), timeouts, and pricing table',
      'DataForSeoClient encapsulating auth headers, endpoint routing, and structured error/cost extraction',
      'dataforseo_responses archive table storing endpoint, request hash, HTTP status, actual cost, and JSONB payload',
      'Artisan smoke command (dataforseo:smoke) verifying credentials and account balance via appendix/user_data',
    ],
    acceptance: [
      'Live/POST endpoints never automatically retry on ambiguous timeouts to prevent double-billing',
      'Every DataForSEO API response records the vendor-reported cost field in dataforseo_spend_ledger',
      'Switching DATAFORSEO_API_BASE_URL to sandbox.dataforseo.com exercises full wiring at $0 cost',
    ],
    deps: ['F-01'],
  },
  {
    id: 'D-02',
    epic: 'E02',
    title: 'Global cross-project DataForSEO cache repository',
    area: 'dataforseo',
    phase: 1,
    size: 'M',
    priority: 'P0',
    summary:
      'Shared PostgreSQL cache keyed by normalized query signature (endpoint + keyword/domain + location + language) with configurable TTLs so duplicate lookups cost $0.',
    scope: [
      'dataforseo_cache table indexed by cache_key hash and expires_at',
      'Configurable TTL tiers: 30 days for keyword volume/overview, 14 days for domain metrics, 7 days for SERP snapshots',
      'Cache hit/miss telemetry surfaced in admin spend diagnostics',
      'Bypass flag (--fresh) limited to explicit user-triggered refreshes with budget checks',
    ],
    acceptance: [
      'Two projects looking up the same keyword in the same locale trigger only one DataForSEO Labs call within TTL',
      'Expired cache entries fall back cleanly to fresh API calls without blocking concurrent readers',
    ],
    deps: ['D-01'],
  },
  {
    id: 'D-03',
    epic: 'E02',
    title: 'DataForSEO Spend Guard & daily/monthly budget caps',
    area: 'dataforseo',
    phase: 1,
    size: 'M',
    priority: 'P0',
    summary:
      'Pre-flight cost estimator and hard circuit-breaker blocking automated or user-triggered purchases when daily, monthly, or per-workspace limits are reached.',
    scope: [
      'DATAFORSEO_SCHEDULED_SPEND global kill-switch (default false outside production)',
      'Per-workspace quota ledger plus global account daily/monthly spend caps',
      'Pre-purchase cost estimation using config/coqui.php prices before submitting tasks',
      'Structured refusal exceptions and admin alerts when balance or cap thresholds are breached',
    ],
    acceptance: [
      'Automated scheduled jobs never spend funds unless DATAFORSEO_SCHEDULED_SPEND=true',
      'Requests exceeding remaining daily cap or workspace credits are rejected before hitting DataForSEO',
      'Spend ledger reconciles recorded call costs against appendix/user_data balance checks',
    ],
    deps: ['D-01'],
  },
  {
    id: 'D-04',
    epic: 'E02',
    title: 'Standard-queue task postback webhook & collector',
    area: 'dataforseo',
    phase: 1,
    size: 'L',
    priority: 'P0',
    summary:
      'Asynchronous task lifecycle handling Standard-queue DataForSEO submissions via signed postback webhooks with a fallback scheduled collector.',
    scope: [
      'dataforseo_tasks table tracking task_id, endpoint, status (pending|collected|failed), payload hash, and postback token',
      'Signed webhook endpoint POST /api/webhooks/dataforseo dispatching collection jobs onto Horizon',
      'Fallback Artisan command (dataforseo:collect-tasks) polling tasks_ready for any uncollected tasks older than threshold',
      'Idempotent result ingestion ensuring duplicate webhooks do not duplicate rank snapshots',
    ],
    acceptance: [
      'Standard SERP tasks submit with postback_url and transition to collected upon webhook delivery',
      'Tasks whose webhook was missed are automatically recovered by dataforseo:collect-tasks within 15 minutes',
      'Duplicate postback deliveries for the same task_id are safely ignored',
    ],
    deps: ['D-01', 'D-03'],
  },

  // ---------------- E03 SERP Intelligence ----------------
  {
    id: 'S-01',
    epic: 'E03',
    title: 'Automated multi-device rank tracker & SERP feature parser',
    area: 'serp',
    phase: 2,
    size: 'XL',
    priority: 'P0',
    summary:
      'Nightly and weekly Horizon batches submitting Standard SERP checks for tracked keywords across desktop/mobile and parsing positions, URLs, and SERP features.',
    scope: [
      'tracked_keywords and keyword_rank_snapshots tables capturing position, rank_group, ranking_url, title, and SERP feature flags',
      'Parser extracting Organic results, AI Overviews, Featured Snippets, Local Pack, and People Also Ask',
      'Competitor rank visibility extracted from the same SERP response at $0 extra API cost',
      'UI rank chart showing visibility score, position distribution, and SERP volatility timeline',
    ],
    acceptance: [
      'One DataForSEO SERP task records positions for the project domain and all configured competitor domains simultaneously',
      'Keywords track desktop and mobile separately with location_code precision',
      'AI Overview citations and Featured Snippet ownership are stored per snapshot',
    ],
    deps: ['F-03', 'D-02', 'D-04'],
  },
  {
    id: 'S-02',
    epic: 'E03',
    title: 'Keyword Explorer & search volume batching',
    area: 'serp',
    phase: 2,
    size: 'L',
    priority: 'P0',
    summary:
      'Interactive keyword research workspace backed by DataForSEO Labs keyword_overview and related_keywords with 700-keyword batching and caching.',
    scope: [
      'Batch up to 700 keywords per Labs keyword_overview request and persist metrics to shared cache',
      'Search volume, 12-month trend sparklines, CPC, competition, keyword difficulty, and search intent tags',
      'Related keywords and keyword suggestions explorer with filtering and CSV export',
      'One-click "Add to Rank Tracker" action directly from explorer rows',
    ],
    acceptance: [
      'Entering 50 keywords serves cached keywords instantly and queries DataForSEO only for cache misses',
      'Keyword Explorer displays volume, KD, intent, and 12-month trend without exceeding workspace credit caps',
    ],
    deps: ['D-02', 'D-03'],
  },
  {
    id: 'S-03',
    epic: 'E03',
    title: 'Competitor keyword gap & domain intersection',
    area: 'serp',
    phase: 2,
    size: 'L',
    priority: 'P1',
    summary:
      'Identify high-opportunity keywords where one or more project competitors rank in top 20 while the project domain is absent or outranked.',
    scope: [
      'DataForSEO Labs domain_intersection integration comparing project domain vs selected competitors',
      'Filter modes: Missing (competitors rank, you do not), Weak (competitors outrank you), Shared',
      'Traffic opportunity scoring combining search volume, competitor rank, and keyword difficulty',
      'Ability to cluster gaps into content briefs or promote directly to Tracked Keywords',
    ],
    acceptance: [
      'Users can compare their domain against 1 to 3 competitors and view missing vs weak keyword tabs',
      'Results are cached per domain-pair signature for 14 days to prevent redundant API spend',
    ],
    deps: ['F-03', 'S-02'],
  },
  {
    id: 'S-04',
    epic: 'E03',
    title: 'Google Search Console & Bing Webmaster first-party overlay',
    area: 'integrations',
    phase: 2,
    size: 'L',
    priority: 'P1',
    summary:
      'OAuth connection to Google Search Console and Bing Webmaster Tools pulling real clicks, impressions, CTR, and average position at zero API cost.',
    scope: [
      'Encrypted OAuth token storage in project_integrations with automatic token refresh',
      'Nightly Horizon job syncing daily GSC query + page performance (respecting 4-day finalization lag)',
      'Bing Webmaster API client pulling query stats and crawl errors',
      'Unified Rank Tracker view blending GSC clicks/impressions alongside DataForSEO tracked positions',
    ],
    acceptance: [
      'Projects with connected GSC show actual clicks, impressions, and CTR next to tracked keywords',
      'Token expiration or revoked permissions surface an actionable reconnect banner without failing other jobs',
    ],
    deps: ['F-03'],
  },

  // ---------------- E04 Hybrid Crawler ----------------
  {
    id: 'C-01',
    epic: 'E04',
    title: 'Coqui Desktop sync API & chunked snapshot ingestion',
    area: 'crawler',
    phase: 3,
    size: 'XL',
    priority: 'P0',
    summary:
      'Authenticated JSON sync API accepting CrawlSummary, CrawlConfig (with secret scrubbing), UrlRecord, LinkRecord, and Issue batches directly from the Coqui Tauri app.',
    scope: [
      'POST /api/v1/crawls/sync initializing a cloud CrawlRun matching coqui-core CrawlSummary fields',
      'POST /api/v1/crawls/{id}/chunks ingesting batched UrlRecord, LinkRecord, and Issue arrays in transactions',
      'POST /api/v1/crawls/{id}/finalize sealing counts, computing issue aggregates, and broadcasting completion',
      'Strict scrubbing of authPassword and sensitive Authorization headers from synced CrawlConfig JSON',
    ],
    acceptance: [
      'Payloads serialized from coqui-core Rust structs with camelCase keys validate and persist losslessly',
      'Syncing a 50,000-URL desktop crawl streams cleanly in 1,000-row chunks without PHP memory exhaustion',
      'authPassword and sensitive header values are never persisted in crawl_runs.config',
    ],
    deps: ['F-02', 'F-03'],
  },
  {
    id: 'C-02',
    epic: 'E04',
    title: 'Historical crawl comparison & technical diff viewer',
    area: 'crawler',
    phase: 3,
    size: 'L',
    priority: 'P1',
    summary:
      'Compare any two crawl snapshots (desktop or cloud) for a project to highlight newly broken URLs, status transitions, title/canonical changes, and issue deltas.',
    scope: [
      'SQL diff queries comparing URL sets and status/indexability fields between baseline and target crawl_run',
      'Issue delta breakdown: New Issues, Resolved Issues, Persistent Issues',
      'Shareable read-only report link with optional token expiry for client/team review',
      'Overlay GSC clicks onto broken/redirected URLs to prioritize fixes by traffic impact',
    ],
    acceptance: [
      'Diffing two 10k-URL crawls computes new/removed URLs and status-code changes in under 2 seconds',
      'Each issue delta links directly to affected URLs with before/after field values',
    ],
    deps: ['C-01'],
  },
  {
    id: 'C-03',
    epic: 'E04',
    title: 'Headless coqui-worker Rust container & Redis job bridge',
    area: 'crawler',
    phase: 3,
    size: 'XL',
    priority: 'P1',
    summary:
      'Lightweight Docker worker running a headless binary built on coqui-core/coqui-crawl to execute scheduled cloud audits and targeted URL checks.',
    scope: [
      'Internal HTTP/Queue bridge dispatching bounded CrawlConfig jobs from Laravel Horizon to coqui-worker',
      'Weekly scheduled cloud crawl per active project (capped by workspace URL tier, e.g. 500-5,000 URLs)',
      'SSRF protection blocking private/loopback IP ranges in cloud worker HTTP transport',
      'Progress heartbeats pushed back to Laravel and broadcast over Reverb',
    ],
    acceptance: [
      'A scheduled Laravel job triggers coqui-worker, streams extracted URLs/issues back via internal API, and marks the run completed',
      'coqui-worker refuses requests targeting RFC1918, loopback, or link-local metadata IPs',
      'Container memory footprint remains bounded under 256MB during 2,000-URL crawls',
    ],
    deps: ['F-01', 'C-01'],
  },
  {
    id: 'C-04',
    epic: 'E04',
    title: 'Live SERP Top-10 content brief & competitor page scraper',
    area: 'crawler',
    phase: 3,
    size: 'L',
    priority: 'P1',
    summary:
      'Given a target keyword SERP, dispatch coqui-worker to fetch the top 10 ranking URLs and extract headings, word counts, schema types, and topic coverage.',
    scope: [
      'On-demand action from Keyword Explorer or Rank Tracker taking the top 10 organic SERP URLs',
      'Targeted 10-URL fetch in coqui-worker extracting H1-H3 outline, word count, meta tags, JSON-LD types, and internal/external link counts',
      'Side-by-side SERP Competitor Structure matrix and downloadable Markdown content brief',
    ],
    acceptance: [
      'Scraping top-10 SERP pages completes within 10 seconds and renders structured outlines for all reachable URLs',
      'Failed/blocked competitor fetches (403/timeout) degrade gracefully without failing the whole brief',
    ],
    deps: ['S-01', 'C-03'],
  },

  // ---------------- E05 Backlinks ----------------
  {
    id: 'B-01',
    epic: 'E05',
    title: 'Backlink profile overview & referring domains explorer',
    area: 'backlinks',
    phase: 4,
    size: 'L',
    priority: 'P1',
    summary:
      'Domain authority metrics, referring domain counts, anchor text cloud, and link attribute breakdown powered by DataForSEO Backlinks API.',
    scope: [
      'DataForSEO backlinks/summary, referring_domains, and anchors integration with 14-day project caching',
      'Filterable Referring Domains table (domain rank, spam score, backlink count, dofollow/nofollow ratio)',
      'Top linked pages view joining backlink authority with crawled internal status codes',
    ],
    acceptance: [
      'Projects display backlink summary, top anchors, and sortable referring domains from cached DataForSEO data',
      'Joining backlink targets against latest CrawlRun highlights high-authority 404 pages needing redirects',
    ],
    deps: ['D-02', 'D-03', 'C-01'],
  },
  {
    id: 'B-02',
    epic: 'E05',
    title: 'New & lost backlinks timeline',
    area: 'backlinks',
    phase: 4,
    size: 'M',
    priority: 'P2',
    summary:
      'Track referring domain velocity and inspect newly acquired or dropped backlinks over weekly/monthly snapshots.',
    scope: [
      'DataForSEO backlinks/history and timeseries_summary storage per project',
      'New vs Lost backlinks delta table with first_seen / last_seen dates',
      'Slack/email digest alerts on sudden backlink drops or spam spikes',
    ],
    acceptance: [
      'Users can inspect new and lost referring domains across configurable date windows',
      'Historical timeseries renders domain link velocity without re-querying DataForSEO on page reload',
    ],
    deps: ['B-01'],
  },
  {
    id: 'B-03',
    epic: 'E05',
    title: 'Active backlink watchlist & link-rot monitor',
    area: 'backlinks',
    phase: 4,
    size: 'L',
    priority: 'P1',
    summary:
      'User-curated watchlist of high-value backlinks verified weekly by coqui-worker to catch link removal, rel="nofollow" flips, 404s, or noindex tags faster than global indexes.',
    scope: [
      'monitored_backlinks table storing source_url, target_url, expected_rel, expected_anchor, last_status, and state (active|lost|rel_changed|error)',
      'Import watchlist URLs from DataForSEO Backlinks API or CSV upload (100-500 links per project)',
      'coqui-worker single-page verification mode checking HTTP status, meta robots, anchor presence, and rel attributes',
      'Immediate notification and diff log when a live backlink changes state',
    ],
    acceptance: [
      'coqui-worker accurately detects removed links, 404/5xx responses, rel=nofollow/sponsored changes, and page-level noindex',
      'Checking 250 watchlist URLs runs at $0 DataForSEO API cost using polite host-level rate limiting',
    ],
    deps: ['C-03', 'B-01'],
  },
  {
    id: 'B-04',
    epic: 'E05',
    title: 'Competitor link intersect & broken backlink reclaim',
    area: 'backlinks',
    phase: 4,
    size: 'M',
    priority: 'P2',
    summary:
      'Discover domains linking to multiple competitors but not your site, plus broken outbound backlinks on competitor domains.',
    scope: [
      'DataForSEO backlinks/domain_intersection query across project + up to 3 competitors',
      'Filter by domain rank, spam score ceiling, and number of linked competitors',
      'Broken backlinks view surfacing dead target pages with live referring domains for outreach',
    ],
    acceptance: [
      'Link Intersect lists referring domains linking to >= 2 competitors while excluding the project domain',
      'Results cache per competitor set to prevent duplicate DataForSEO Backlinks charges',
    ],
    deps: ['F-03', 'B-01'],
  },

  // ---------------- E06 Hosting Moat ----------------
  {
    id: 'H-01',
    epic: 'E06',
    title: 'Runnr hosting post-deploy SEO smoke tests',
    area: 'integrations',
    phase: 5,
    size: 'L',
    priority: 'P1',
    summary:
      'Signed webhook integration for Runnr hosting deployments that triggers an immediate targeted coqui-worker smoke check against critical URLs, robots.txt, and sitemaps.',
    scope: [
      'POST /api/webhooks/runnr/deploy verifying HMAC signature and mapping site_id to Coqui Project',
      'Targeted 50-200 URL smoke crawl checking robots.txt disallow regressions, accidental global noindex, 5xx errors, and canonical host flips',
      'Deploy Smoke Test status badge and webhook callback back to Runnr deployment log',
    ],
    acceptance: [
      'A Runnr deploy webhook triggers a smoke crawl and reports pass/fail within 60 seconds',
      'Accidental Disallow: / or staging noindex leaked to production triggers an immediate high-severity alert',
    ],
    deps: ['C-03'],
  },
  {
    id: 'H-02',
    epic: 'E06',
    title: 'Server access log bot & AI crawler telemetry',
    area: 'integrations',
    phase: 5,
    size: 'L',
    priority: 'P1',
    summary:
      'Ingest aggregated edge/server log telemetry from Runnr-hosted sites to chart Googlebot, Bingbot, and AI bot (GPTBot, ClaudeBot, PerplexityBot) crawl hits and status codes.',
    scope: [
      'POST /api/v1/telemetry/bot-logs endpoint accepting batched hourly bot hit summaries',
      'bot_crawl_logs partitioned/indexed table tracking bot_family, status_bucket (2xx/3xx/4xx/5xx), hits, bytes, and top crawled paths',
      'Crawl Budget dashboard highlighting wasted bot hits on 404s, redirect chains, and parameterized URLs',
    ],
    acceptance: [
      'Hosted sites visualize daily Googlebot vs AI crawler hit volume and error rates without running an external crawler',
      'High-frequency 404 paths hit by Googlebot are automatically flagged for redirect remediation',
    ],
    deps: ['H-01'],
  },
  {
    id: 'H-03',
    epic: 'E06',
    title: 'GA4 & Plausible organic traffic attribution',
    area: 'integrations',
    phase: 5,
    size: 'M',
    priority: 'P2',
    summary:
      'Connect GA4 Data API and Plausible Stats API to overlay landing-page organic traffic, engagement, and AI-assistant referral sessions onto crawl URLs.',
    scope: [
      'GA4 and Plausible adapters syncing daily landing-page metrics into project analytics snapshots',
      'AI assistant referral grouping (ChatGPT, Perplexity, Claude, Gemini) tracked alongside organic search',
      'Page Performance view joining CrawlUrl health + GSC clicks + GA4/Plausible sessions by normalized URL path',
    ],
    acceptance: [
      'Crawl URL tables display organic sessions and AI referrals alongside technical SEO issues',
      'Projects can configure either GA4, Plausible, or both seamlessly',
    ],
    deps: ['S-04', 'C-01'],
  },
  {
    id: 'H-04',
    epic: 'E06',
    title: 'Subscription billing, plan tiers & usage metering',
    area: 'platform',
    phase: 5,
    size: 'L',
    priority: 'P2',
    summary:
      'Laravel Cashier + Pennant feature gating aligning subscription tiers with variable DataForSEO COGS (tracked keywords, SERP credits, cloud crawl URL caps) and Runnr bundle perks.',
    scope: [
      'Plan tiers (Free/Desktop Sync, Pro, Agency, Runnr Hosting Add-on) enforced via Laravel Pennant features',
      'Monthly allowance resets and DataForSEO credit metering tied to workspace Spend Guard',
      'Cashier Stripe checkout, customer portal, and webhook synchronization',
    ],
    acceptance: [
      'Workspaces cannot exceed plan limits on tracked keywords or cloud crawl URLs',
      'Runnr-hosted projects automatically unlock native deploy smoke tests and bot telemetry entitlements',
    ],
    deps: ['D-03', 'H-01'],
  },
];
