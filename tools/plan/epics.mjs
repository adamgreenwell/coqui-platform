// Coqui SEO Platform backlog - epics.
// Covers the Laravel 13 cloud platform (coquiseo.com) paired with the Coqui desktop crawler
// and the Runnr hosting ecosystem.

export const epics = [
  {
    id: 'E01',
    slug: 'foundation',
    title: 'Laravel 13 Foundation, Workspaces & Coolify Stack',
    summary:
      'Application scaffold, PostgreSQL 17 and Redis infrastructure, Horizon/Reverb workers, workspace multi-tenancy, Sanctum authentication for Coqui Desktop, and Coolify Docker Compose deployment.',
    outcome:
      'A working Laravel 13 platform deployed on bare-metal Coolify where users can register workspaces, create SEO projects with competitors, and connect Coqui Desktop via Sanctum tokens.',
    phase: 1,
    size: 'XL',
    priority: 'P0',
  },
  {
    id: 'E02',
    slug: 'dataforseo-core',
    title: 'DataForSEO & SE Ranking Multi-Provider Core',
    summary:
      'Unified DataForSEO v3 and SE Ranking Data API clients, Standard-queue postback ingestion, cross-project response caching, strict credit/USD spend guards, and provider routing.',
    outcome:
      'Every DataForSEO and SE Ranking call goes through audited, budget-guarded clients that route keywords and domain checks to the lowest-cost provider while reserving SERP queues and backlinks for DataForSEO.',
    phase: 1,
    size: 'XL',
    priority: 'P0',
  },
  {
    id: 'E03',
    slug: 'serp-intelligence',
    title: 'Rank Tracking, Keyword Explorer & GSC/Bing Overlay',
    summary:
      'Scheduled desktop/mobile rank tracking by location, keyword research and competitor content gap discovery via DataForSEO Labs, and first-party Search Console + Bing Webmaster performance overlays.',
    outcome:
      'Users track keyword rankings over time (including AI Overviews and SERP features), uncover competitor keyword gaps, and compare DataForSEO positions with actual GSC/Bing clicks and impressions.',
    phase: 2,
    size: 'XL',
    priority: 'P0',
  },
  {
    id: 'E04',
    slug: 'hybrid-crawler',
    title: 'Coqui Desktop Sync & coqui-worker Cloud Crawler',
    summary:
      'Bidirectional JSON sync protocol matching coqui-core structs, chunked crawl ingestion and historical diffing, a headless Rust coqui-worker container for scheduled cloud audits, and live SERP top-10 content scraping.',
    outcome:
      'Local Coqui Desktop crawls upload cleanly to the cloud for sharing and historical diffs, while coqui-worker handles unattended scheduled crawls and real-time SERP content briefs.',
    phase: 3,
    size: 'XL',
    priority: 'P1',
  },
  {
    id: 'E05',
    slug: 'backlinks',
    title: 'Backlink Intelligence & Active Link-Rot Watchlist',
    summary:
      'Domain backlink overview, referring domains and anchor distributions from DataForSEO, paired with targeted coqui-worker checks that verify priority backlinks for removal, 404s, or nofollow flips.',
    outcome:
      'Users get full macro backlink discovery from DataForSEO without maintaining a web-scale index, plus near-real-time link-rot alerts on their highest-value earned links.',
    phase: 4,
    size: 'L',
    priority: 'P1',
  },
  {
    id: 'E06',
    slug: 'hosting-moat',
    title: 'Runnr Hosting Webhooks, Bot Telemetry & Traffic Attribution',
    summary:
      'Post-deploy SEO smoke tests triggered by Runnr hosting webhooks, edge/access log bot telemetry (Googlebot, Bingbot, AI bots), GA4/Plausible traffic attribution, and platform billing readiness.',
    outcome:
      'Sites hosted on Runnr gain zero-config deploy protection and server-log crawl budget analytics that standalone SEO platforms cannot match.',
    phase: 5,
    size: 'L',
    priority: 'P1',
  },
];
