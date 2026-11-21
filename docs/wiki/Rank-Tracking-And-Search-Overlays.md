# Rank Tracking and Search Overlays

Phase 2 delivers daily/weekly keyword visibility and competitor SERP intelligence powered by DataForSEO paired with zero-cost first-party search data.

## Automated Rank Tracker (`S-01`)

- Keywords are stored in `tracked_keywords` scoped by `project_id`, `device` (`desktop` or `mobile`), `location_code` (e.g. `2840` United States), `language_code`, and `cadence`.
- `php artisan dataforseo:run-rank-batches` submits Standard-queue SERP tasks via `DataForSeoClient::submitStandardSerpTasks()`.
- One SERP response evaluates **both** the project domain and every configured `ProjectCompetitor` simultaneously (`keyword_rank_snapshots`) at zero incremental API cost.
- Snapshots capture `rank_group`, `rank_absolute`, `ranking_url`, `ranking_title`, `has_ai_overview`, `has_featured_snippet`, and structured `serp_features`.
- Rank checks set `load_async_ai_overview` (env `DATAFORSEO_LOAD_ASYNC_AI_OVERVIEW`, default on) so AI Overviews that load after the page are captured. It is billed per check on top of the task (`prices.serp_ai_overview_surcharge`), and the pre-flight estimate includes it.
- **AI Overview citations** are stored per keyword and day in `keyword_ai_overview_citations` (position, domain, URL, title); a re-check the same day replaces them. Each domain's `serp_features` records `ai_overview_citation_position` and `local_pack_owned`.
- The project's own snapshot also keeps the page's `ai_overview_position`, `ai_overview_citation_count`, `people_also_ask` questions and `local_pack` listings.
- **Trends:** the keywords page charts the last 30 days of visibility, keywords in the top 3/10/20, and volatility (average positions moved by keywords re-checked that day; dropping out of the top 100 counts as position 101). Each day uses every keyword's latest check on or before it (`RankTrendService`). The SERP history modal charts rank over time for the project and competitors, with the keyword's volatility, the latest AI Overview's sources, People Also Ask and the Local Pack.

## Keyword Explorer & Competitor Gaps (`S-02`, `S-03`)

- **Keyword Explorer:** Batches up to 700 keywords per `dataforseo_labs/google/keyword_overview/live` call and caches results globally for 30 days in `dataforseo_cache`. Keywords a successful lookup returned no data for are cached too (`no_data: true`, 7 days via `cache_ttl_hours.keyword_no_data`), so asking again isn't billed again; a failed lookup caches nothing.
- **Competitor Keyword Gaps:** Calls `domain_intersection` to surface **Missing** (competitors rank top 20, project absent) and **Weak** (competitors outrank project) opportunities, cached for 14 days per competitor set.

## Google Search Console & Bing Overlay (`S-04`)

- First-party credentials are encrypted at rest in `project_integrations` (`credentials_encrypted` cast).
- Nightly Horizon syncs pull query and page clicks, impressions, and CTR while respecting the 4-day search finalization lag.
- Rank Tracker views overlay actual GSC clicks directly beside DataForSEO tracked positions and AI Overview presence.
- Search Console query rows are stored per device (`search_performance_queries.device`: `desktop`, `mobile`, `tablet`), so desktop and mobile keyword rows show their own clicks, impressions and position. Rows synced before the split are stored as `all`, shown until the next sync replaces them for the dates it covers. Bing has no device breakdown and is stored as `all`.
- Bing syncs also pull daily crawl stats (`GetCrawlStats`) into `bing_crawl_stats`. The project overview's Bing card shows the last 30 days: pages crawled, pages in the index, crawl errors, and their 4xx, 5xx, robots.txt and DNS/timeout breakdown.
- When an integration needs reconnecting, the reason is stored in `project_integrations.status_reason` (for example "Token refresh failed: invalid_grant" or "Bing API key invalid or unauthorized.") and shown on the Search Console and Bing reconnect banners and the overview cards. It clears once the integration is connected again.
