# Coqui SEO Platform — Competitive Feature Catalog & Replication Blueprint

> **Purpose:** Map the full feature landscape of Ahrefs, Semrush, and Moz Pro to Coqui SEO Platform's (`coquiseo.com`) hybrid architecture—showing how we replicate (and differentiate from) the Big Three without running a petabyte-scale global crawler fleet.

---

## 1. Executive Positioning & Pricing Wedge (2026 Market)

The "Big Three" SEO SaaS platforms charge $100–$500+/month largely because they maintain massive distributed crawler clusters and petabyte-scale link graphs, plus heavy multi-channel bloat.

| Platform | Entry / Starter | Mid / Growth | Scale / Agency | Core Strengths | Key Customer Pain Points |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Ahrefs** | **$29/mo** (Starter, heavily capped) / **$129/mo** (Lite) | **$249/mo** (Standard) | **$449/mo** (Advanced) | Gold-standard backlink index, Site & Content Explorer, Brand Radar (AI visibility) | Tight "report credit" metering on lower tiers; no discounts; API locked behind ~$1,500+/mo enterprise plans |
| **Semrush** | **$139.95/mo** (Pro / SEO) | **$249.95/mo** (Guru) | **$499.95/mo** (Business) | Deep all-in-one marketing suite (SEO + PPC + Social + Content + Local + AI Visibility) | Expensive add-on stack (AI, Local, extra seats); overwhelming UX for pure SEO teams |
| **Moz Pro** | **$49/mo** (Starter) / **$99/mo** (Standard) | **$179/mo** (Medium) | **$299/mo** (Large) | Domain Authority (DA) / PA / Spam Score benchmark, clean UX, early AI visibility | Smaller/slower link index; lighter technical crawler than Screaming Frog or Ahrefs |
| **Coqui SEO** *(Target)* | **~$29–$39/mo** (Solo) | **~$79–$99/mo** (Pro) | **~$179–$199/mo** (Agency) | **Unlimited local crawling** (`Coqui Desktop`), wholesale multi-provider APIs, agent-native **MCP server**, **Runnr hosting + bot telemetry moat** | Newer brand/authority metric (solved via blended/familiar authority scoring + transparent data provenance) |

### How We Undercut by 50–70% Without Bleeding Margin
1. **Zero-COGS Technical Crawling:** `Coqui Desktop` (Rust/Tauri) runs unlimited deep crawls on the user's own hardware and streams snapshots to the cloud (`/api/v1/crawls/*`).
2. **Standard Queues + Cross-Tenant Caching:** DataForSEO Standard postbacks (`$0.0006`–`$0.002` range vs. expensive Live calls) + shared 14–30 day caches mean popular keywords/domains are fetched once and served to all tenants at $0 marginal cost.
3. **SE Ranking Bulk Offloading:** Cheap ASCII keyword screening and domain overviews route to SE Ranking (`~1,000 keywords / 100 credits`), reserving DataForSEO for SERPs, backlinks, non-ASCII/numeric edge cases, and AI/GEO.
4. **First-Party & Hosting Moat:** GSC, Bing Webmaster, GA4, Plausible, Umami, Google PSI/CrUX, and Runnr server-log bot telemetry are $0 API COGS and deliver real-world truth the Big Three can't easily see.

---

## 2. Multi-Provider Data Stack & Third-Fallback Architecture

```text
                    ┌──────────────────────────────────────────┐
                    │       Coqui SEO Platform (Laravel 13)    │
                    │   SeoProviderRouter + Cache + SpendGuard │
                    └────────────────────┬─────────────────────┘
                                         │
       ┌─────────────────────┬───────────┴──────────┬──────────────────────┐
       ▼                     ▼                      ▼                      ▼
┌──────────────┐    ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│ DataForSEO   │    │   SE Ranking    │    │  3rd Fallback   │    │  Owned & Free   │
│  v3 (PAYG)   │    │  Data API       │    │ (Split by Need) │    │  1st-Party /    │
│              │    │  (Credit-based) │    │                 │    │  Rust Crawler   │
├──────────────┤    ├─────────────────┤    ├─────────────────┤    ├─────────────────┤
│• SERP API    │    │• Bulk Keywords  │    │• SERP: SERPHouse│    │• Coqui Desktop  │
│• Backlinks   │    │  (/export)      │    │  (live fallback)│    │• coqui-worker   │
│• Labs API    │    │• Domain Overview│    │• Authority/Links│    │• GSC / Bing     │
│• AI Opt/GEO  │    │• Append-only    │    │  Open PageRank +│    │• GA4/Plausible/ │
│• Content Anl │    │  archive        │    │  Moz Links API  │    │  Umami          │
│• Business/Map│    │                 │    │  (recommended)  │    │• Google PSI/CrUX│
└──────────────┘    └─────────────────┘    └─────────────────┘    │• Runnr Bot Logs │
                                                                  └─────────────────┘
```

### Provider Responsibilities & Guardrails

| Layer | Provider | Role in Coqui SEO | Cost & Reliability Guardrails | Repo Status |
| :--- | :--- | :--- | :--- | :--- |
| **Layer 1: Primary Macro Engine** | **DataForSEO v3** (PAYG) | SERP rank tracking (Standard postbacks), AI Overviews, Backlinks API, Labs (keywords, ranked keywords, domain/page intersection, search intent), AI Optimization (LLM Mentions/GEO), Content Analysis, Business Data (Local/Maps) | `DataForSeoCacheRepository` (14d/30d TTL), `DataForSeoSpendGuard` (daily/monthly caps + `DATAFORSEO_SCHEDULED_SPEND` kill switch), sandbox mode | **Built** (`app/Domains/Serp/DataForSeo/*`) |
| **Layer 2: Bulk Efficiency Layer** | **SE Ranking Data API** (Credits) | Bulk ASCII keyword screening (`/v1/keywords/export`, ~1,000 kw/call) and domain organic visibility (`/v1/domain/overview/db`) | `SeRankingAcceptanceRule` (partitions non-ASCII, numeric-only, search operators to DataForSEO), payload bisector, 429 `Retry-After` backoff, `SeRankingSpendGuard`, append-only `seranking_archive` | **Built** (`app/Domains/Serp/SeRanking/*`) |
| **Layer 3a: SERP 3rd Fallback** | **SERPHouse** *(active)* / **Serper.dev** *(optional alt)* | Live Google SERP fallback when DataForSEO queue/postbacks stall or miss a priority check | `RankFallbackSpendGuard` + per-page `SerphouseClient::googlePage()` | **Built** (`app/Domains/Serp/RankFallback/*`) |
| **Layer 3b: Backlinks & Authority 3rd Fallback** | **Open PageRank (DomCop)** + **Moz Links API** *(recommended)* | SE Ranking's standalone API lacks backlinks and SERPHouse is SERP-only—leaving DataForSEO as a single point of failure for link data. **Open PageRank** gives a free 0–10 domain authority fallback; **Moz Links API** (~$20–$250/mo) adds industry-familiar DA/PA/Spam Score + top links. Reserve **Majestic API** (~$399/mo) for agency/enterprise scale. | Cache authority scores 30d; only trigger Moz Links fallback on DataForSEO failure or explicit DA/Spam Score enrichment | **Recommended Gap** |
| **Layer 4: Owned & Zero-COGS** | **Coqui Desktop**, **`coqui-worker`**, **First-Party APIs**, **Runnr** | Unlimited technical site crawls, scheduled cloud audits, active watchlist link-rot checks, live top-10 SERP content scraping, GSC/Bing/GA4/Plausible/Umami overlays, Google PSI/CrUX, Runnr post-deploy smoke tests & server bot logs | $0 external SEO API COGS; SSRF protection (`SsrfProtectionGuard`) and credential scrubbing on sync | **Mostly Built** (`Crawls`, `Backlinks`, `Integrations`) |

---

## 3. Cohesive Feature Catalog (10 Pillars)

Status legend:
- **Built** — Core backend services/models/jobs exist in `app/Domains/`.
- **Partial** — Foundation or one side exists; UI, exporter, or secondary provider wiring remains.
- **Net-New Gap** — Not yet implemented; target for upcoming roadmap phases.

### Pillar 1: Domain & Site Explorer

| Feature | Big 3 Equivalents (Ahrefs / Semrush / Moz) | Coqui Replication Blueprint (Provider / Layer) | COGS & Caching Strategy | Current Status in Repo |
| :--- | :--- | :--- | :--- | :--- |
| **Domain Overview Dashboard** | Ahrefs Site Explorer / Semrush Domain Overview / Moz Domain Overview | `SeoProviderRouter` combines SE Ranking `/v1/domain/overview/db` + DataForSEO Labs `ranked_keywords` & Backlinks `summary` + GSC/GA4/Plausible | Cache domain summaries 14d; archive SE Ranking snapshots in `seranking_archive` | **Built / Partial** (`SeoProviderRouter`, `BacklinkProfileService`) |
| **Top Pages & Subdomains Explorer** | Ahrefs Top Pages & Subdomains / Semrush Top Organic Pages | DataForSEO Labs `relevant_pages` + `subdomains` merged with GSC page clicks/impressions | Cache 14d per domain; free refresh when GSC is connected | **Net-New Gap** |
| **Organic Competitors & Share of Voice** | Ahrefs Competing Domains / Semrush Organic Competitors | DataForSEO Labs `competitors_domain` + `ProjectCompetitor` + `VisibilityScore` | Cache 14d; compute Share of Voice locally from tracked keywords | **Partial** (`ProjectCompetitor`, `VisibilityScore`) |
| **Lightweight Paid / PPC Snapshot** | Semrush Advertising Research / Ahrefs Paid Keywords | DataForSEO Labs `ranked_keywords` filtered by `item_types: ["paid"]` + Google Ads SERP ads | Piggybacks on existing SERP/Labs calls—minimal extra COGS | **Net-New Gap** |

### Pillar 2: Keyword Research & Intent

| Feature | Big 3 Equivalents (Ahrefs / Semrush / Moz) | Coqui Replication Blueprint (Provider / Layer) | COGS & Caching Strategy | Current Status in Repo |
| :--- | :--- | :--- | :--- | :--- |
| **Keyword Explorer (Volume, KD, CPC, Trend)** | Ahrefs Keywords Explorer / Semrush Keyword Overview / Moz Keyword Explorer | `SeoProviderRouter` batches ASCII queries to SE Ranking `/v1/keywords/export`, routes non-ASCII/numeric/operators to DataForSEO Labs `keyword_overview/live` | 30d cross-tenant cache; ~1,000 kw/100 credits on SE Ranking | **Built** (`KeywordResearchService`, `SeoProviderRouter`) |
| **Keyword Ideas, Questions & Search Intent** | Semrush Keyword Magic Tool / Ahrefs Matching Terms & Questions | DataForSEO Labs `keyword_suggestions`, `related_keywords`, `keyword_ideas`, and `search_intent` | 30d cross-tenant cache keyed by `(seed, location, language)` | **Partial** (`KeywordResearchService` — expand ideas/intent tabs) |
| **Keyword Gap / Domain Intersection** | Ahrefs Content Gap / Semrush Keyword Gap | DataForSEO Labs `domain_intersection` + `page_intersection` across `ProjectCompetitor` domains | 14d cache per sorted domain tuple | **Built** (`KeywordGapService`) |
| **SERP-Overlap Keyword Clustering** | Semrush Keyword Strategy Builder / Ahrefs Parent Topic | Cluster keywords locally when their stored top-10 SERP URLs overlap by >=3–4 URLs | **$0 extra API COGS** — computed from stored `KeywordRankSnapshot` SERPs | **Net-New Gap** |
| **Traffic Potential & First-Party CTR Curve** | Ahrefs Traffic Potential | Combine top-ranking page's `relevant_pages` traffic + project's own GSC actual CTR curve | Low COGS; GSC CTR calibration is $0 | **Net-New Gap** |

### Pillar 3: Rank Tracking & SERP Intelligence

| Feature | Big 3 Equivalents (Ahrefs / Semrush / Moz) | Coqui Replication Blueprint (Provider / Layer) | COGS & Caching Strategy | Current Status in Repo |
| :--- | :--- | :--- | :--- | :--- |
| **Multi-Device & Geo Rank Tracker** | Ahrefs Rank Tracker / Semrush Position Tracking / Moz Rank Tracker | DataForSEO SERP Standard queue + `/api/webhooks/dataforseo` postbacks, with `SerphouseClient` live fallback | Standard queue postbacks; daily/weekly cadence gated by plan tier | **Built** (`RecordSerpRankSnapshots`, `RankTrendService`, `SerphouseClient`) |
| **SERP Features & AI Overviews** | Semrush SERP Features / Ahrefs SERP Overview | `SerpResultParser` extracts featured snippets, PAA, local pack, video, and `KeywordAiOverviewCitation` | Extracted from existing SERP payload (**$0 extra COGS**) | **Built** (`SerpResultParser`, `KeywordAiOverviewCitation`) |
| **Visibility Score & Share of Voice** | Semrush Visibility % / Moz Search Visibility | `VisibilityScore` calculates weighted CTR visibility across project & competitors | **$0 extra COGS** — computed from stored snapshots | **Built** (`VisibilityScore`) |
| **Keyword Cannibalization Detection** | Semrush Position Tracking Cannibalization | Flag tracked keywords where >=2 project URLs swap or co-rank in top 20, cross-checked with GSC query-page splits | **$0 extra COGS** — pure DB query over `KeywordRankSnapshot` + GSC | **Net-New Gap** |
| **SERP Volatility / Algo Sensor** | Semrush Sensor / MozCast | Aggregate daily rank-flux across all anonymized projects in Coqui's DB to surface Google algo tremors | **$0 extra COGS** — powered by our own multi-tenant rank telemetry | **Net-New Gap** |

### Pillar 4: Backlink Intelligence & Link Building

| Feature | Big 3 Equivalents (Ahrefs / Semrush / Moz) | Coqui Replication Blueprint (Provider / Layer) | COGS & Caching Strategy | Current Status in Repo |
| :--- | :--- | :--- | :--- | :--- |
| **Backlink Profile, Referring Domains & Anchors** | Ahrefs Backlink Profile / Semrush Backlink Analytics / Moz Link Explorer | DataForSEO Backlinks API (`summary`, `backlinks`, `referring_domains`, `anchors`) | 14d cross-tenant cache; paginated & capped by plan tier | **Built** (`BacklinkProfileService`) |
| **New & Lost Backlinks Timeline** | Ahrefs New/Lost Backlinks / Semrush Backlinks Audit | DataForSEO `timeseries_new_lost_summary` + `BacklinkHistorySnapshot` | Daily/weekly snapshot sync | **Built** (`BacklinkTimelineService`, `BacklinkHistorySnapshot`) |
| **Competitor Link Intersect & Broken Reclaim** | Ahrefs Link Intersect & Broken Backlinks | DataForSEO `domain_intersection` (backlinks) + 404 target filtering | Cached per competitor set | **Built** (`BacklinkIntersectService`) |
| **Active Watchlist & Link-Rot Monitor** | Ahrefs Link Monitoring *(stronger: live Rust check)* | `coqui-worker` directly fetches watchlisted source URLs to verify `rel`, anchor text, status code, and link presence | **$0 external API COGS** — bounded Rust worker | **Built** (`BacklinkVerificationService`, `VerifyMonitoredBacklinksJob`) |
| **Blended Authority Score & 3rd Fallback** | Ahrefs DR / Moz DA / Semrush AS | Primary: DataForSEO `rank` normalized 0–100; Fallback/Enrichment: **Open PageRank** (free) + **Moz Links API** (DA/PA/Spam Score) | Cache 30d; Moz Links only on fallback or explicit enrichment | **Net-New Gap** |
| **Unlinked Brand Mentions & Toxic Link Audit** | Semrush Brand Monitoring & Backlink Audit (Disavow) | DataForSEO Content Analysis `search` minus existing referring domains; spam heuristics + `.txt` disavow export | On-demand or weekly Pro/Agency run | **Net-New Gap** |

### Pillar 5: Technical Site Audit & Hybrid Crawling

| Feature | Big 3 Equivalents (Ahrefs / Semrush / Moz) | Coqui Replication Blueprint (Provider / Layer) | COGS & Caching Strategy | Current Status in Repo |
| :--- | :--- | :--- | :--- | :--- |
| **Unlimited Deep Technical Crawls** | Ahrefs Site Audit / Semrush Site Audit *(we beat their URL caps)* | `Coqui Desktop` (Rust/Tauri) crawls locally and streams chunks to `/api/v1/crawls/sync` | **$0 server crawl COGS** | **Built** (`IngestDesktopCrawlSnapshot`, `CrawlSyncPayload`) |
| **Scheduled Cloud Audits & Crawl Diffs** | Semrush Site Audit Compare Crawls / Ahrefs Audit History | `coqui-worker` (`coqui-core`) runs bounded cloud crawls; `CrawlDiffService` diffs URLs, status codes, titles, canonicals, and issues | Bounded single-binary Rust worker | **Built** (`DispatchCloudCrawlJob`, `CrawlDiffService`, `CrawlDiffReport`) |
| **Core Web Vitals & Lighthouse Integration** | Semrush Site Audit CWV / Ahrefs Performance | Free **Google PageSpeed Insights API** + **CrUX API** for key templates/URLs | **$0 API COGS** (free Google APIs, rate-limited queue) | **Net-New Gap** |
| **Page, `robots.txt` & `llms.txt` Change Monitor** | ContentKing / Ahrefs Page Inspect | `coqui-worker` hashes critical URLs, `robots.txt`, `sitemap.xml`, and `llms.txt` on schedule and alerts on diffs | **$0 external API COGS** | **Net-New Gap** |
| **Automated Internal Link Opportunity Finder** | Ahrefs Internal Link Opportunities | Match high-authority/traffic pages from `CrawlUrl` + GSC against keyword-relevant target pages lacking internal links | **$0 extra API COGS** — computed from existing crawl graph | **Net-New Gap** |

### Pillar 6: Content & On-Page Optimization

| Feature | Big 3 Equivalents (Ahrefs / Semrush / Moz) | Coqui Replication Blueprint (Provider / Layer) | COGS & Caching Strategy | Current Status in Repo |
| :--- | :--- | :--- | :--- | :--- |
| **Live SERP Top-10 Content Briefs** | Semrush SEO Content Template / Surfer-style outlines | `coqui-worker` + `HtmlPageOutlineExtractor` scrape live top-10 SERP URLs for headings, word count, entities, and outline | **$0 external API COGS** beyond the SERP lookup | **Built** (`ContentBriefService`, `GenerateContentBriefJob`) |
| **Content Decay & Striking-Distance Finder** | Semrush On Page SEO Checker / GSC Decay tools | Cross-reference GSC 90d vs. prior 90d clicks/impressions + keywords ranking pos 4–20 | **$0 extra API COGS** — pure GSC + rank snapshot math | **Net-New Gap** |
| **Content Explorer Lite** | Ahrefs Content Explorer / BuzzSumo | DataForSEO Content Analysis API (`search`, `summary`, `phrase_trends`, `sentiment`) | On-demand per query, cached 7d | **Net-New Gap** |
| **On-Page SEO Grader & Editor Checklist** | Moz Page Optimization / Semrush SEO Writing Assistant | Score draft/live URLs against `ContentBrief` targets (title, H1–H3, keyword density, schema, internal links) | **$0 extra API COGS** | **Net-New Gap** |

### Pillar 7: AI Search Visibility / GEO (2026 Differentiator)

| Feature | Big 3 Equivalents (Ahrefs / Semrush / Moz) | Coqui Replication Blueprint (Provider / Layer) | COGS & Caching Strategy | Current Status in Repo |
| :--- | :--- | :--- | :--- | :--- |
| **Google AI Overviews Citation Tracking** | Ahrefs AI Overview tracking / Semrush AIO | `KeywordAiOverviewCitation` parsed directly from DataForSEO SERP Advanced responses | **$0 extra COGS** (included in SERP responses) | **Built** (`KeywordAiOverviewCitation`) |
| **Multi-LLM Brand & Prompt Visibility** | Ahrefs Brand Radar / Semrush AI Visibility Toolkit | **DataForSEO AI Optimization API** (`llm_mentions`, `llm_responses` for ChatGPT, Gemini, Perplexity, Claude) + `ai_keyword_data` | Metered per prompt/workspace tier; weekly batching | **Net-New Gap** |
| **Server-Log AI Bot Telemetry** | *Rarely offered by Big Three* (unique Coqui + Runnr moat) | `BotTelemetryService` ingests server access logs for `GPTBot`, `ClaudeBot`, `PerplexityBot`, `Googlebot`, `Bingbot` | **$0 external API COGS** | **Built** (`BotTelemetryService`, `BotCrawlLog`) |
| **`llms.txt` & AI-Readiness Auditor** | Emerging GEO tooling | Extend `coqui-core` / `coqui-worker` checks for `llms.txt`, AI bot `robots.txt` rules, JSON-LD entity clarity, and SSR vs. JS-only content | **$0 extra API COGS** | **Net-New Gap** |

### Pillar 8: Local SEO

| Feature | Big 3 Equivalents (Ahrefs / Semrush / Moz) | Coqui Replication Blueprint (Provider / Layer) | COGS & Caching Strategy | Current Status in Repo |
| :--- | :--- | :--- | :--- | :--- |
| **Local Pack & Geo-Grid Rank Tracking** | Semrush Map Rank Tracker / BrightLocal / Moz Local | DataForSEO Google Maps SERP (`serp/google/maps/task_post`) across lat/lng grid points | Metered add-on / Agency tier; Standard queue postbacks | **Net-New Gap** |
| **GBP & Review Monitoring** | Semrush Listing Management / Moz Local | DataForSEO Business Data API (`google/my_business_info`, `google/reviews`) | Weekly/monthly sync per location | **Net-New Gap** |
| **NAP Citation Consistency Checker** | Moz Local / Semrush Listing Management | Targeted directory checks via `coqui-worker` or DataForSEO Business Data | Optional Local add-on | **Net-New Gap** |

### Pillar 9: First-Party Analytics, Reporting & Agent Workflows

| Feature | Big 3 Equivalents (Ahrefs / Semrush / Moz) | Coqui Replication Blueprint (Provider / Layer) | COGS & Caching Strategy | Current Status in Repo |
| :--- | :--- | :--- | :--- | :--- |
| **Search & Traffic Overlays** | Semrush/Ahrefs GSC & GA4 connectors *(plus Plausible & Umami)* | `GoogleSearchConsoleClient`, `BingWebmasterClient`, `Ga4AnalyticsClient`, `PlausibleAnalyticsClient`, `UmamiAnalyticsClient` | **$0 API COGS** | **Built** (`app/Domains/Integrations/*`) |
| **Post-Deploy SEO Smoke Tests** | *Unique Coqui + Runnr hosting moat* | `RunnrDeployWebhookHandler` + `RunnrDeploySmokeService` verify status codes, canonicals, `noindex`, and sitemaps on every deploy | **$0 external API COGS** | **Built** (`RunnrDeployWebhookHandler`, `RunnrDeploySmokeService`) |
| **Agent-Native Public MCP Server** | Ahrefs/Semrush MCP *(Coqui is Laravel-native)* | Public `laravel/mcp` server + Laravel Boost exposing SEO, crawl, and rank tools to AI agents | **$0 extra COGS**; gated by workspace quotas | **Built** (`app/Mcp/*`) |
| **White-Label Client Reports & Share Links** | Semrush My Reports / Agency PDFs / Moz Custom Reports | `PlanFeature::WhiteLabelReports` flag exists; build PDF/HTML report builder + tokenized read-only client share URLs | **$0 external API COGS** | **Partial** (`PlanFeature::WhiteLabelReports`) |
| **Anomaly Alerts & Scheduled Digests** | Semrush/Ahrefs alerts | `BacklinkDigestService` exists; generalize to rank drops, crawl regressions, AI bot spikes, and deploy smoke failures | **$0 extra COGS** | **Partial** (`BacklinkDigestService`) |

### Pillar 10: Intentional Non-Goals (Protecting Focus & Margins)

To stay lean, fast, and 50–70% cheaper than Semrush/Ahrefs, Coqui SEO **intentionally excludes**:
1. **Full Social Media Publishing & Scheduling** (Buffer/Hootsuite territory; Semrush bloat).
2. **Full PPC / Display Ad Creative Spy Suites** (SpyFu/Semrush AdClarity territory; huge data cost for non-SEO buyers).
3. **Self-Hosted Global Web-Scale Link Crawler** (petabyte/proxy trap; delegated to DataForSEO + Moz/Open PageRank fallback).

---

## 4. Prioritized Build-Next Waves

### Wave A — Zero-COGS High-ROI Wins (Build First)
Features computed from data Coqui **already collects** or **free Google APIs**:
1. **Keyword Cannibalization Detector** (`KeywordRankSnapshot` + GSC query-page splits).
2. **Content Decay & Striking-Distance (Pos 4–20) Finder** (GSC 90d vs. prior 90d).
3. **SERP-Overlap Keyword Clustering** (cluster tracked keywords sharing >=3 top-10 URLs).
4. **Automated Internal Link Opportunity Finder** (`CrawlUrl` graph + GSC top pages).
5. **Core Web Vitals Integration** (free Google PSI & CrUX APIs).
6. **Page, `robots.txt` & `llms.txt` Change Monitor + AI-Readiness Checks** (`coqui-worker`).

### Wave B — Core Competitive Parity & Resilience
1. **Multi-LLM AI Visibility / GEO** (DataForSEO AI Optimization API: ChatGPT, Gemini, Perplexity, Claude mentions).
2. **Backlink & Authority 3rd Fallback** (`OpenPageRankClient` free + `MozLinksClient` optional fallback behind a unified authority/backlink interface).
3. **Top Pages & Subdomains Explorer** (DataForSEO Labs `relevant_pages` + `subdomains` + GSC merge).
4. **White-Label Client Report Builder & Shareable Links** (completing `PlanFeature::WhiteLabelReports`).

### Wave C — Agency & Local Expansion
1. **Local Pack & Geo-Grid Rank Tracker** (DataForSEO Google Maps SERP).
2. **Unlinked Brand Mentions & Content Explorer Lite** (DataForSEO Content Analysis API).
3. **Toxic Link / Disavow Audit & Export**.
