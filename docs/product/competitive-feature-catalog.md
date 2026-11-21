# Coqui SEO — Competitive Feature Catalog & Replication Plan

**Status:** working document, 2026-11-21
**Audience:** product and engineering
**Question it answers:** which features do Ahrefs, Semrush and Moz sell, which of those can we reproduce from purchased APIs plus our own crawler, what does each one cost us per customer, and in what order do we build them.

> Companion documents: [Product Direction](../wiki/Product-Direction.md) (why the platform exists), [Development Roadmap](../wiki/Development-Roadmap.md) (what shipped), [DataForSEO Integration and Cost Controls](../wiki/DataForSEO-Integration-And-Cost-Controls.md) (how spend is capped).

---

## 1. The strategy in one page

We are not competing with Ahrefs on data depth. We cannot: their index is the product, and it costs them eight figures a year to maintain. We compete on **the 80% of the workflow a small team or agency actually uses, at a fifth of the price, with two things they cannot buy** — a hosting platform's view of the origin server, and a desktop crawler that makes deep technical audits free.

Three kinds of feature, three cost behaviours:

| Class | Source | Marginal cost | Examples |
|---|---|---|---|
| **Purchased** | DataForSEO, SE Ranking, SERPHouse | Linear per call — the thing that kills margin | rank checks, keyword volumes, backlink profiles, competitor intersections |
| **Owned** | `coqui-worker` (Rust) + Coqui Desktop | Flat — a container and some bandwidth | site audits, content-brief page extraction, link-rot verification, deploy smoke tests |
| **First-party** | GSC, Bing, GA4, Umami, Plausible, Runnr logs | Zero — it is the customer's own data | traffic attribution, query data, bot telemetry, deploy correlation |

The economic spine of the business is therefore: **flat-cost features are the margin; metered features must be sold per unit, never "unlimited", and must be cached across tenants wherever the answer is the same for everyone.**

Two rules for the catalog:

1. Every feature must map to one of the three classes, with a measured unit cost.
2. No feature may be sold at a tier where its worst-case cost exceeds ~30% of that tier's revenue.

Rule 2 is currently violated in four places. That is section 2.

---

## 2. Unit economics — the part that decides whether the wedge works

### 2.1 The billing-unit trap, and a 10× bug in the spend guard

DataForSEO bills SERP data **per 10 results**, not per request. `config/coqui.php` records `serp_standard_task = 0.0006`, which is the price of one 10-result page. But rank tasks are submitted with `'depth' => 100` (`RunRankBatchesCommand.php:151`) — ten pages — so one check actually costs **$0.0060**.

`submitStandardSerpTasks()` (`DataForSeoClient.php:725`) reserves `unitCost + aiOverviewSurcharge` once per task and ignores depth. Every rank check is therefore written to the ledger at one tenth of its real cost, and the daily cap, monthly cap, per-workspace budget and drift alert all operate on a number that is 10× too small.

| Tier | Checks/month at plan limits | Ledger reserves | Real cost at depth 100 | Undercount |
|---|---|---|---|---|
| Free | 429 | $0.26 | $2.57 | 10× |
| Personal | 9,000 | $5.40 | $54.00 | 10× |
| Pro | 30,000 | $18.00 | $180.00 | 10× |
| Agency | 300,000 | $180.00 | $1,800.00 | 10× |

The caps do not bind. A Free workspace can spend roughly $26/month against an assumed $0.50 and still look compliant, and four of them exhaust the platform's $100 monthly cap while the ledger reads $10. This is the most important number in the business and it is presently wrong in the permissive direction. Fix before any pricing work: estimate `ceil(depth / 10) × base`, and apply the same multiplier to the AI Overview and PAA surcharges if the vendor applies them per page.

### 2.2 Cost per operation, corrected

| Operation | Unit | Cost |
|---|---|---|
| One SERP page of 10 results, Standard queue | 1 page | $0.0006 |
| One rank check at depth 100 | 10 pages | **$0.0060** |
| One rank check at depth 20 | 2 pages | $0.0012 |
| AI Overview surcharge | per page | +$0.0006 |
| People Also Ask surcharge | per page | +$0.00015 |
| Live SERP, 10 results (content brief) | 1 page | $0.0020 |
| Keyword volume, 1,000 keywords | 1 task | $0.06 |
| Keyword difficulty, 1,000 keywords | task + rows | $0.132 |
| Backlink profile: summary + 3 × 1,000 rows | 3 tasks | $0.18 |
| Authority scores, 1,000 domains (`bulk_ranks`) | 1 task | $0.06 |
| Domain intersection, 5 competitors × 1,000 keywords | 5 tasks | $0.66 |
| Site audit, 1,000 pages, **our crawler** | flat | **≈$0.03–0.05** (modelled) |
| Site audit, 1,000 pages, DataForSEO OnPage | base | $0.15 |
| ... with JavaScript | | $1.50 |
| ... with browser rendering (CWV) | | $5.10 |

Two lines matter more than the rest. Our own crawler is **3× cheaper than the cheapest third-party audit and roughly 100× cheaper than a rendered one** — that is the structural reason audits can be unlimited. And a rank check at depth 100 costs ten times what the code currently believes.

### 2.3 What the current plans actually cost at their own limits

Daily tracking plus one backlink refresh and a month of keyword research:

| Tier | Price | Tracked keywords | Rank COGS at depth 10 | at depth 20 | at depth 100 | Margin at depth 100 |
|---|---|---|---|---|---|---|
| Free | $0 | 100 (weekly) | $0.26 | $0.51 | $2.57 | −$2.57 |
| Personal | $19 | 300 (daily) | $5.40 | $10.80 | $54.00 | **−$35.00** |
| Pro | $49 | 1,000 (daily) | $18.00 | $36.00 | $180.00 | **−$131.00** |
| Agency | $199 | 10,000 (daily) | $180.00 | $360.00 | $1,800.00 | **−$1,601.00** |

Every paid tier loses money at its advertised limits if depth stays at 100. Depth is therefore not a technical detail; it is the pricing lever, worth a 10× swing on the largest cost line.

### 2.4 Anchoring the price against what people already pay

Observed 2026-11-21 from official pricing pages and help centres. Re-verify before anything customer-facing.

| Platform | Entry | Mid | Top | Notable limits on the mid tier |
|---|---|---|---|---|
| Ahrefs | Starter $29 (monthly only) | Lite $129 | Standard $249, Advanced $449, Enterprise from ~$1,499 | 750 tracked keywords **updated weekly**, 100k crawl credits, 2,500 rows/report, 6 months history |
| Semrush | SEO $139 | Starter $199 | Pro+ $299, Advanced/Enterprise custom | 500 tracked keywords daily at $139; 1,500 daily at $299 |
| Moz Pro | Starter $49 | Standard $99 | Medium $179, Large $299 | 300 keyword rankings, 400k pages crawled/mo, 1 user |
| SE Ranking | Core $129 (~$103 annual) | Growth $279 (~$223) | Enterprise custom | 10 projects, **2,000 keywords tracked daily**, 250k audit pages/mo |
| Screaming Frog | $279/yr per seat | — | — | Unlimited crawling, 300+ issues, log-file analysis — the benchmark for crawl depth |

"50–70% cheaper" is not true at the entry tier: $29 price-matches Ahrefs Starter and sits 41% below Moz Starter, but SE Ranking Core offers far more quota for the money. The discount is real against the tiers people actually buy:

| Coqui | vs Ahrefs | vs Semrush | vs Moz | vs SE Ranking |
|---|---|---|---|---|
| Solo $29 | = Starter | −79% vs SEO | −41% vs Starter | −78% vs Core |
| Pro $79 | −39% vs Lite | −43% vs SEO | −20% vs Standard | −39% vs Core |
| Agency $199 | −20% vs Standard | −33% vs Pro+ | −33% vs Large | −29% vs Growth |

**SE Ranking is the price floor, and it owns its data.** At $129 it sells 2,000 keywords tracked daily — about 60,000 depth-100 checks a month, which costs us $360 if we buy them. They can do that because they own the keyword and rank database; we rent ours. We cannot out-quota them on rank tracking, and any plan designed to try loses money.

### 2.5 Sell SERP credits, not keywords

Ahrefs sells credits and Semrush sells daily quotas for the same reason: rank checks are the cost, and a keyword count silently implies a frequency (100 keywords daily is 30× 100 keywords monthly).

Proposal: **one credit = one SERP page of 10 results**. A depth-100 check costs 10 credits. That mirrors the vendor's own billing unit, so a mispriced feature shows up immediately instead of after a 10× surprise.

| Plan | Price | Credits/month | Depth-10 daily | Depth-100 daily | COGS at full use | Margin |
|---|---|---|---|---|---|---|
| Free | $0 | 100 | 3 | 0 | $0.06 | — |
| Solo | $29 | 3,000 | 100 | 10 | $1.80 | **94%** |
| Pro | $79 | 10,000 | 333 | 33 | $6.00 | **92%** |
| Agency | $199 | 45,000 | 1,500 | 150 | $27.00 | **86%** |
| Top-up | $10 | +3,000 | +100 | +10 | $1.80 | **82%** |

Depth is now a product decision rather than an accident: default depth 10–20 on lower tiers (where 90% of action is), and charge 10 credits for a deep check on Pro and Agency. A customer who wants Ahrefs-style depth-100 tracking on 1,000 keywords costs us $180/month, which is exactly what the Agency credit pool is sized for.

Compared per check with the market:

| Product | Price | Checks/month | Cost per depth-100 check |
|---|---|---|---|
| Ahrefs Lite | $129 | ~3,250 (750 weekly) | $0.040 |
| Semrush SEO | $139 | ~15,000 (500 daily) | $0.009 |
| SE Ranking Core | $129 | ~60,000 (2,000 daily) | **$0.002** |
| Coqui Pro | $79 | 1,000 deep (10,000 credits) | $0.079 |
| Coqui Agency | $199 | 4,500 deep (45,000 credits) | $0.044 |

At depth 100 we are competitive with Ahrefs and Semrush per check but not with SE Ranking. At depth 10 we are far cheaper than everyone. That is the honest positioning: **depth where it matters, unlimited elsewhere**.

### 2.6 Two more defects to fix before selling

**The Free tier is upside down, and it can take down paying customers.** `planLimits()` gives Free a **$25/month** DataForSEO allowance and Personal **$8**. Free is three times more generous than the $19 plan. Combined with the 10× undercount, a Free workspace can spend roughly $260 of real data against a $25 assumption before the guard notices — and the platform-wide cap defaults to $5/day and $100/month.

**Allowances are marketed as value but enforced as cost.** "$150/mo Live SEO Data Allowance" on a $49 plan reads as a feature; in code it is permission to spend $150. Rename it or size it to the price.

## 3. What is already built

Grounded in `routes/web.php`, `routes/api.php`, `routes/ai.php` and the domain services. "Built" means a user can reach it in the UI; "partial" means the data exists but the workflow is thin.

### Research

| Feature | Status | Where |
|---|---|---|
| Keyword overview (volume, CPC, competition, difficulty) | Built | `KeywordExplorerController`, `DataForSeoKeywordParser` |
| Related keywords | Built | `dataforseo_labs/google/related_keywords/live` |
| Keyword gap / competitor domain intersection | Built | `KeywordGapService`, `KeywordGapController` |
| Bulk keyword tracking from research screens | Built | `keyword-explorer/track`, `keyword-gap/track` |
| Saved keyword lists / tags / folders | Missing | — |
| Content gap by page and content decay | Missing | — |
| Keyword clustering / intent classification | Missing | — |

### Rank tracking

| Feature | Status | Where |
|---|---|---|
| Multi-device tracked keywords, scheduled batches | Built | `RunRankBatchesCommand`, `TrackedKeyword` |
| SERP feature parsing: AI Overview citations, PAA, Local Pack | Built | `SerpRankTracker`, `keyword_ai_overview_citations` |
| Trend / volatility charts, per-keyword history | Built | `TrackedKeywordController`, `TrendChart` |
| Fallback provider for stalled checks | Built | `RankFallback`, SERPHouse |
| Rank-drop alerts to the customer | Missing | only operator spend alerts exist |
| Competitor rank tracking in the same grid | Partial | competitors tracked within history modal, not as a full grid |
| Share-of-voice / visibility score | Partial | visibility computed from snapshots, not surfaced as a headline metric |

### Backlinks

| Feature | Status | Where |
|---|---|---|
| Backlink profile (summary, referring domains, anchors, pages) | Built | `BacklinkProfileService` |
| New/lost timeline and velocity | Built | `BacklinkTimelineService` |
| Watchlist link-rot monitor verified by our own crawler | Built | `BacklinkVerificationService`, `coqui:verify-backlinks` |
| Competitor link intersect | Built | `BacklinkIntersectService` |
| Alerts + weekly email/Slack digest | Built | `EvaluateBacklinkAlertsCommand`, `SendBacklinkDigestsCommand` |
| Broken backlink reclaim workflow | Partial | 404 detection exists in crawls, no prospect/outreach pipeline |
| Link-building prospect lists, contact discovery, outreach status | Missing | — |

### Technical SEO

| Feature | Status | Where |
|---|---|---|
| Desktop crawl sync, chunked and idempotent | Built | `CrawlSyncController` |
| Cloud crawl via `coqui-worker` | Built | `RunScheduledCrawlsCommand`, `CloudCrawlController` |
| Crawl comparison with before/after and per-rule drill-down | Built | `CrawlCompareController` |
| Revocable share links (30 days) | Built | `CrawlDiffReport` |
| Deploy smoke tests (robots, sitemap, zero-URL, badge) | Built | `RunnrDeploySmokeService` |
| Bot / AI-crawler telemetry and crawl-budget waste | Built | `BotCrawlLog`, `BotTelemetryController` |
| Prioritised site-audit surface with a health score | Partial | rules come from `coqui-core`; no score or issue catalog across runs |
| Core Web Vitals / PageSpeed | Missing | free CrUX + PSI available |
| Scheduled PDF/white-label reports | Partial | share links only; `white-label-reports` feature gate exists |
| Log-file upload analysis (bring your own logs) | Missing | Runnr telemetry only |

### Integrations and traffic

| Feature | Status | Where |
|---|---|---|
| GSC (queries, pages, per-device), Bing (crawl stats) | Built | `SyncSearchPerformanceCommand` |
| GA4 (property picker), Plausible, Umami | Built | `SyncTrafficAnalyticsCommand` |
| Organic vs AI traffic attribution per page | Built | `TrafficAttributionController` |
| Competitor traffic estimates / top pages | Missing | requires Labs ranked-keywords + CTR curve |

### Platform

| Feature | Status | Where |
|---|---|---|
| Workspaces, roles, invites, audit trail, scoped API tokens | Built | `WorkspaceMemberController`, `workspace_activities` |
| Stripe billing, Pennant plan gates, portal, invoices | Built | `StripeBillingService` |
| Public MCP server (3 tools) | Built | `CoquiSeoServer` |
| Public REST API for customers | Missing | desktop API is internal-only |
| Brand monitoring / mentions | Missing | — |
| Local SEO (GBP, grid) | Missing | Local Pack parsed in SERPs only |
| PPC / ad intelligence | Missing | DataForSEO Ads endpoints available |

---

## 4. Competitive feature matrix

✓ included in the standard paid product · ~ partial or higher tiers · — not offered

| Workflow | Ahrefs | Semrush | Moz Pro | SE Ranking | Coqui today |
|---|---|---|---|---|---|
| Domain overview (traffic, top pages, keyword count) | ✓ | ✓ | ✓ | ✓ | — |
| Keyword volume / CPC / difficulty | ✓ | ✓ | ✓ | ✓ | ✓ |
| Keyword ideas, related, questions | ✓ | ✓ | ✓ | ✓ | ✓ (partial) |
| Keyword lists, tags, saved views | ✓ | ✓ | ✓ | ✓ | — |
| Search intent / clustering | ~ | ✓ | — | ~ | — |
| Rank tracking | ✓ weekly on every self-serve tier | ✓ daily | ✓ weekly | ✓ daily | ✓ (daily capable) |
| Tracked keyword quota, mid tier | 750 | 500 daily | 300 | 2,000 daily | 333 daily at $79 |
| Multi-location / multi-device | ✓ | ✓ | ~ | ✓ | ~ |
| SERP features + AI Overview citations | ✓ | ✓ | ✓ | ✓ | ✓ |
| Share of voice / visibility score | ✓ | ✓ | ✓ | ✓ | ~ |
| Rank-drop alerts | ✓ | ✓ | ✓ | ✓ | — |
| Competitor keyword gap | ✓ | ✓ | ~ | ✓ | ✓ |
| Competitor traffic estimate | ✓ (add-on $99) | ✓ | **—** | ✓ | — |
| Top pages / content gap / decay | ✓ | ✓ | ~ | ✓ | — |
| Backlink profile + referring domains | ✓ | ✓ | ✓ | ✓ | ✓ |
| Authority metric | DR | Authority Score | DA/PA/Spam | Domain Trust | ~ (DataForSEO rank, unlabelled) |
| New/lost backlinks | ✓ | ✓ | ✓ | ✓ | ✓ |
| Link intersect | ✓ | ✓ | ✓ | ✓ | ✓ |
| Broken-link reclaim | ✓ | ✓ | ~ | ✓ | ~ |
| Link-rot monitoring | — | ~ | — | — | **✓ unique** |
| Site audit, scheduled | ✓ 180+ issues | ✓ | ✓ | ✓ 115+ | ~ |
| Crawl comparison over time | ✓ Standard+ | ✓ | ~ | ✓ Growth | ✓ |
| JS rendering in audit | ✓ | ✓ | ~ | ✓ | ~ (worker/desktop) |
| Core Web Vitals | ✓ | ✓ | ~ | ✓ | — |
| Content briefs / AI writing | ✓ add-on $99 | ✓ | ✓ (2–50/mo) | ✓ | ✓ |
| AI Overview citation tracking | ✓ | ✓ | ✓ | ✓ | ✓ |
| LLM prompt tracking (ChatGPT/Gemini/Perplexity) | ✓ from $50/mo | ✓ Starter+ | ✓ 150–600 prompts | ✓ 25/day | — |
| AI share of voice / sentiment | ✓ | ✓ | ✓ | ✓ | — |
| AI-ready technical audit (bot access, llms.txt) | ✓ | ✓ | ~ | ~ | ~ (robots + bot logs) |
| AI crawler telemetry (GPTBot etc.) | — | — | — | — | **✓ unique** |
| Deploy smoke tests | — | — | — | — | **✓ unique** |
| Local SEO / GBP | ~ | ✓ | ✓ (separate) | ✓ | — |
| PPC / ad intelligence | ~ | ✓ | — | ~ | — |
| Own-site traffic attribution (GA4/GSC) | ~ | ✓ | ~ | ✓ | ✓ |
| White-label / PDF reports | ~ paid add-on | ✓ $20/mo | ~ Large+ | ✓ +$69/mo | ~ share links |
| Public API | ✓ paid, unit-based | ✓ Advanced+ | ✓ from $20/mo | ✓ from $45/mo | — (MCP only) |
| MCP server | ✓ paid | ✓ Starter+ | ✓ beta | ✓ all plans | ✓ |
| Seats / roles | ✓ 1 + $40–80 | ✓ from $45 | ✓ 1–3 | ✓ 1–3 | ✓ |

Three rows are still uniquely ours — link-rot verification, AI crawler telemetry, deploy diagnostics — because none of those platforms sits on the hosting layer. Everything else is now table stakes, **including MCP and AI-visibility tracking**: all five competitors shipped both in 2025–26, so their absence from a new entrant reads as a gap, not a differentiator.

## 5. Replication playbook

For every feature we do not have, this is how we get it without owning an index. "Unit" is the cost of one user action, before cross-tenant caching.

### 5.1 Research

| Feature | How we replicate it | Source | Unit | Verdict |
|---|---|---|---|---|
| Keyword volume, CPC, difficulty | Direct API call; results are identical for every tenant, so cache by `keyword+location+language` for 30 days and share | DataForSEO Labs `keyword_overview/live` | $0.00013 / keyword | **Build** — already built; add shared cache |
| Keyword ideas, related, questions | `related_keywords` plus the PAA questions we already store on every rank check (free) | Labs + our own snapshot table | $0.00013 / row | **Build** — PAA-derived view is free |
| Keyword clustering | Cluster stored SERP top-10 URL sets by overlap; no API call if the keyword has been checked | Our DB | $0 | **Build** — differentiator at zero cost |
| Search intent | Classify from the stored SERP composition (shopping block, local pack, PAA, video) | Our DB | $0 | **Build** |
| Volume history / seasonality | Labs monthly volume history; fall back to 12 months of our own snapshots | Labs | minimal | **Later** |
| Bulk lists, tags, saved views | Pure product work on existing tables | — | $0 | **Build** (table stakes) |

### 5.2 Rank tracking

| Feature | How we replicate it | Source | Unit | Verdict |
|---|---|---|---|---|
| Daily rankings at depth 100 | Standard queue, never live; share the SERP snapshot across tenants for the same keyword/location/device/day | DataForSEO SERP standard | $0.0006–0.00135 | **Keep** — but cap frequency by tier |
| SERP features, AI Overview citations, PAA, Local Pack | Already parsed from the same response | — | $0 | **Built** |
| Rank-drop and new-ranking alerts | Rule engine over snapshots (position delta, entering/leaving top 10, SERP feature gained/lost) | Our DB | $0 | **Build next** — table stakes, free to run |
| Share of voice / visibility score | Weighted average of position × volume across the tracked set | Our DB + cached volumes | $0 | **Build** — headline metric |
| Competitor ranking grid | Add competitor domains to the same snapshot parse (no extra calls; we already request the SERP) | Existing responses | $0 | **Build** — cheap win |

The competitor grid is the single best cost/benefit item in this document: the SERP response already contains the top 100 results, so tracking three competitors costs nothing beyond storage.

### 5.3 Backlinks

| Feature | How we replicate it | Source | Unit | Verdict |
|---|---|---|---|---|
| Profile, referring domains, anchors, pages | DataForSEO Backlinks; cache per domain 14 days and share across tenants | Backlinks API | ~$1.18 / full profile | **Built** — add shared cache and cap rows by tier |
| Authority metric | Publish DataForSEO's `rank` (0–100) as "Authority", do not invent a competing DR scale | Backlinks summary | included | **Build** — label honestly |
| New/lost timeline | `timeseries_new_lost_summary`; already built | Backlinks API | $0.02 | **Built** |
| Link intersect | `backlinks/domain_intersection` | Backlinks API | ~$0.02–0.10 | **Built** |
| Link rot monitoring | Our own crawler re-fetches the linking page and checks the link, rel and noindex | coqui-worker | flat | **Built — this is a moat** |
| Broken-link reclaim | Take competitor 404s from a crawl, then ask which pages link to them | Backlinks `pages` per dead URL | $0.024+ per URL | **Build**, limited to top 25 per run |
| Prospect lists and outreach | Derive from intersect + rot + broken links; store status ourselves | Our DB | $0 | **Later** — product work, not data |
| Anchor text cloud, spam score, dofollow ratio | Fields already returned by the API | — | included | **Build** — UI only |

### 5.4 Technical SEO

| Feature | How we replicate it | Source | Unit | Verdict |
|---|---|---|---|---|
| Site audit with prioritised issues | Coqui's rule engine (shared with the desktop app) writes issues; add a weighted health score and issue catalog in PHP | coqui-worker / desktop | flat | **Build** — the score is UI work; the data exists |
| Core Web Vitals | CrUX API for field data at origin/URL level, PSI for lab data | Google, free | $0 | **Build** — free table stakes |
| Crawl comparison over time | Already built | — | $0 | **Built** |
| Scheduled audits by cadence | `coqui:run-scheduled-crawls` already dispatches; add cadence per plan | worker | flat | **Build** |
| Log file analysis | Parse uploaded logs (self-hosted); Runnr customers get it automatically | own parser | flat | **Later** |
| White-label PDF reports | Headless Chrome rendering the existing share-link page | own infra | flat | **Build** — Agency differentiator |

The audit surface is where we can beat the incumbents outright: their audits consume crawl credits, ours consume CPU we already run. An audit that costs Ahrefs a metered page credit costs us nothing, so we can offer unlimited crawl frequency.

### 5.5 Competitor intelligence

| Feature | How we replicate it | Source | Unit | Verdict |
|---|---|---|---|---|
| Competitor organic keywords | Labs `ranked_keywords` for a domain, cached 7–14 days and shared | Labs | ~$0.00012 / keyword | **Build** |
| Estimated organic traffic | Sum(volume × position CTR curve) over those keywords; present as a range, never a point estimate | Our compute | $0 | **Build** |
| Top pages | Group the same rows by URL | Our compute | $0 | **Build** |
| Keyword gap | Already built (domain intersection) | Labs | $1.21 / 10k rows | **Built** |
| Content gap and decay | Diff competitor pages against our crawl; watch positions over stored snapshots | Our DB | $0 | **Build** |
| Competitor traffic history | Labs historical endpoints, or accumulate our own from launch | Labs | metered | **Later** |
| Backlink gap | Backlinks `domain_intersection` | Backlinks API | metered | **Built** |

### 5.6 AI and local visibility

| Feature | How we replicate it | Source | Unit | Verdict |
|---|---|---|---|---|
| AI Overview citations | Already parsed per rank check | — | $0 | **Built** |
| AI crawler visibility (GPTBot, ClaudeBot, PerplexityBot) | Runnr edge logs; own-site log upload for everyone else | First-party | $0 | **Built for Runnr — make it a standalone product** |
| LLM mention tracking ("what does ChatGPT say about us") | DataForSEO AI Optimization `ai_optimization/llm_mentions/search/live` returns mentions and citations per prompt | DataForSEO AI Optimization | ~$0.1/task + ~$0.001/row (vendor table; confirm on a test invoice) | **Build next** — every competitor now sells it and the unit cost is low, but per-row billing means a prompt-heavy tier can still run away |
| AI referral traffic | GA4 / Umami referrer filtering (already ingested) | First-party | $0 | **Built** |
| Local pack presence | Parsed from rank checks | — | $0 | **Partial** |
| Local grid tracking | 5×5 or 7×7 grid = 25–49 SERP calls per keyword per day | DataForSEO / SERPHouse | metered, expensive | **Add-on only** — never bundle |
| Google Business Profile audit | Requires GBP API access (approval) | Google | $0 | **Later** |

### 5.7 Reporting and platform

| Feature | How we replicate it | Source | Unit | Verdict |
|---|---|---|---|---|
| Public REST API | Thin, scoped, rate-limited wrapper over existing services | — | $0 | **Build** — see #152 |
| MCP server | Already built; expand tools as features land | — | $0 | **Built — distribution advantage** |
| Scheduled email reports | Already have the digest pattern for backlinks; extend to rank and audit | — | $0 | **Build** |
| PDF / white-label | Headless Chrome | — | flat | **Build** (Agency) |
| Brand mentions | DataForSEO content analysis / SERP brand queries | metered | **Later** |
| PPC intelligence | DataForSEO Ads endpoints | metered | **Won't build yet** — thin margin, distracts from SEO |

---

### 5.8 The third fallback, and the backlink single point of failure

Today the provider split is:

| Layer | Provider | Covers | Failure mode |
|---|---|---|---|
| 1 | DataForSEO | SERP, Labs, **backlinks**, on-page, content analysis, AI Optimization | Account suspension, price change, or regional outage blanks the backlink product |
| 2 | SE Ranking | Bulk keyword screening and domain visibility | **No backlinks in the standalone API**, so it cannot substitute |
| 3 | SERPHouse | Live SERP fallback for stalled rank checks (already wired) | SERP only |

That makes DataForSEO a single point of failure for every link-related screen. Options for a real third source, priced:

| Option | Cost | What it gives | What it does not |
|---|---|---|---|
| **Moz Links API** | Free tier 50 rows/mo; Starter ~$20/mo for 3,000 rows; scales to millions | DA/PA/Spam Score, top links, anchor text — the metric buyers already recognise | Not a full crawl of your profile; row-metered |
| **Open PageRank** (Keywords Everywhere) | Free plan 30,000 domains/mo at 60 req/min | Cheap 0–10 authority proxy for bulk scoring | Different scale from DA/DR; no link rows |
| **Common Crawl** | Free (bandwidth + compute) | Web-graph "who links to whom" for large-scale discovery | Weeks stale, thin for small sites, no anchor detail |
| **Majestic** | ~$399+/mo | Long history, trusted-flow metrics | Priced above our whole margin |

Recommendation: keep DataForSEO primary, add **Moz Links API as a contracted fallback for summary + authority only** (so an outage degrades to DA/PA and cached rows rather than a blank page), and keep Open PageRank as the free last resort for bulk domain scoring. Add a provider health check with an automatic failover switch rather than waiting for a customer to report an empty report.

---

## 6. Wave A — features that cost nothing to run

These derive new insight from data we already store or already fetch. They are the cheapest way to close the perceived gap, and several are genuinely better than the incumbents' equivalents because they join our crawl data with first-party traffic.

| Feature | How it works | Data already present | Gap to close |
|---|---|---|---|
| **Striking-distance finder** | Queries ranking 4–20 with impressions but weak CTR, ranked by potential clicks | `search_performance_queries` (28-day window, per device) | None — build now |
| **Cannibalisation detector** | One query ranking on two or more URLs | **Not yet** — GSC is synced as `query+date+device` and `page+date` separately, never `query+page` | Add the `query,page,date` dimension to the sync and one table; data is free |
| **Content decay** | Compare 90 days against the prior 90 days | Only 28 finalized days are synced today | Needs a one-off backfill of the 16 months GSC retains, then it maintains itself |
| **SERP-overlap clustering** | Group keywords whose top-10 result sets overlap, so one page can target many terms | **Not yet** — only `ranking_url` is stored, not the top-10 result set | Store top-10 URLs on each rank check (same response, no extra cost), then cluster offline |
| **Internal link opportunities** | Pages with rank potential that no internal page links to, weighted by GSC demand | `crawl_links`, `crawl_urls`, GSC queries | None — build now |
| **Core Web Vitals** | Field data per origin/URL, lab data per page | None | Free CrUX + PageSpeed Insights APIs |
| **robots.txt / sitemap / llms.txt change monitor** | Diff on every deploy and daily; alert on blocked paths, sitemap shrinkage, missing llms.txt | Deploy smoke already fetches robots and sitemap | Add llms.txt, persist the diff, alert |
| **AI Overview citation tracking for competitors** | Which domains are cited for each tracked keyword, over time | `keyword_ai_overview_citations` | UI only |
| **Rank and SERP-feature alerts** | Position delta, entering/leaving top 10, gained/lost AI Overview or PAA | `keyword_rank_snapshots` | Rule engine + notifications |
| **Share of voice** | Weighted visibility across the tracked set | Snapshots + cached volumes | Compute + headline card |

Two of these need a schema decision before they can be "free": cannibalisation needs the `query+page` GSC dimension, and clustering needs the top-10 result set persisted. Both are additive, both are populated by calls we already make, and together they unlock four of the ten items.

---

## 7. What we cannot replicate, and how to position around it

Honesty here is worth more than another feature. A prospect who catches us overstating parity leaves; one who understands the trade-off stays.

| Their advantage | Why we cannot match it | What we say instead |
|---|---|---|
| Fresh global link index (Ahrefs, Semrush, Moz: 40T+ links) | They crawl the web continuously; we purchase a slice of someone else's crawl | "Backlink data from a commercial index, refreshed on a 7–14 day cycle, with your own link-rot verified by our crawler — the combination is what you act on." |
| Domain Authority / DR as an industry currency | DA/DR are their proprietary scales; inventing a third scale nobody trades in is worse than using a known one | Publish "Authority (0–100)" sourced from DataForSEO, and never brand it as DR. |
| 10+ years of historical rankings | We start today | "We start your history on day one and never lose it. Import GSC for 16 months of backfill." |
| Accurate traffic estimates | Ours is a model (keywords × volume × CTR). Incumbents' estimates are also models, but better tuned | Show the estimate next to the customer's **real** GA4/GSC numbers so they learn the model's error. No competitor does this. |
| Content Explorer / web-wide content search | Requires a web-scale content index | "We analyse the pages that already rank for your keywords — the ten that matter — not 10 billion you will never read." |
| PPC/ad intelligence at scale | Separate data business | Out of scope for v1. |
| Local grid tracking at scale | 25–49 calls per keyword per day | Sold as a metered add-on, priced per grid refresh. |

The two things we have that they structurally cannot copy:

1. **Origin-level truth.** Runnr tells us what the deploy did, what Googlebot actually fetched, and whether a redirect is bleeding crawl budget. An SEO tool that sees the server is a different category of evidence.
2. **Zero-marginal-cost crawling.** Coqui Desktop and the cloud worker make deep, frequent audits free. Every competitor meters the crawl; we can make it unlimited.

---

## 8. Build order

### Slice 0 — Fix the economics (before selling)
1. Correct the 10× rank-check undercount in the spend guard and re-derive every cap (#162).
2. Correct the Free/Personal allowance inversion and set a real free-tier cap (#154, #150-adjacent billing work).
3. Raise the platform-wide cap or move it to a per-provider budget that scales with paying customers.
4. Add cross-tenant rank snapshot caching (same keyword/location/device/day = one call).
5. Introduce credit-based plans with depth as the multiplier; add top-up packs.

### Slice 1 — Table stakes parity (4–6 weeks)
Rank-drop alerts · competitor grid in the rank table · share-of-voice metric · competitor top pages and traffic estimate · content gap/decay · site-audit health score · CrUX/CWV · PDF reports · public REST API.

### Slice 2 — The moat (what we sell against them)
AI crawler visibility report as a standalone product · deploy-to-ranking correlation · AI Overview citation tracking for competitors · MCP tool expansion · Runnr "fix this redirect" remediation.

### Slice 3 — Later, if the wedge holds
Local grid add-on · brand mentions · LLM answer monitoring · link prospecting/outreach.

---

## 9. Metrics to run the business on

| Metric | Target | Why |
|---|---|---|
| COGS / revenue by tier | < 25% | The whole strategy depends on it |
| Cache hit rate on rank checks | > 30% | Turns metered cost into storage |
| Metered calls with no cache and no user trigger | < 5% | Catches runaway scheduled spend |
| Free-tier burn per signup | < $0.50 / month | Otherwise free users fund nothing |
| Cost per active project | tracked weekly | Detects plan design drift |
| Share of revenue from top-ups | 10%+ | Proves the pricing is honest rather than loss-leading |

---

## 10. Sources and assumptions

Observed 2026-11-21. Competitor pricing pages render prices with JavaScript, so several figures were confirmed through secondary sources; re-verify on the official page before anything customer-facing.

- Ahrefs plans and prices — https://ahrefs.com/pricing (confirmed via https://echai.ventures/explore/tools/ahrefs)
- Semrush plans and prices — https://www.semrush.com/pricing/ (confirmed via https://backlinko.com/semrush-pricing)
- Moz Pro plans — https://moz.com/products/pro (confirmed via https://www.stackscored.com/pricing/seo-tools/moz-pro/)
- SE Ranking plans — https://seranking.com/subscription.html
- SE Ranking API credits — https://seranking.com/api-pricing.html
- Moz Links API — https://moz.com/products/api (row pricing via https://apis.io/plans/moz/moz-plans-pricing/)
- Open PageRank — https://keywordseverywhere.com/openpagerank/pricing
- DataForSEO AI Optimization API — https://dataforseo.com/ai-optimization-api
- DataForSEO unit costs — `config/coqui.php` price table (the numbers the spend guard reserves); re-check against https://dataforseo.com/pricing
- Plan allowances — `Workspace::planLimits()` and `StripeBillingService::plans()`
- Feature status — `routes/web.php`, `routes/api.php`, `app/Domains`

**Open questions before this becomes a pricing page**

1. Does the Free tier keep a DataForSEO allowance at all, and what stops signup farming?
2. Do we sell checks as a transparent unit, or keep "keywords" as marketing and meter behind the scenes?
3. Is the Agency tier weekly-only at 10,000 keywords, or daily with top-ups?
4. Do we publish an "Authority" number sourced from DataForSEO, or avoid a named metric entirely?
