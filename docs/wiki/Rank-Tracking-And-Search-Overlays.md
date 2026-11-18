# Rank Tracking and Search Overlays

Phase 2 delivers daily/weekly keyword visibility and competitor SERP intelligence powered by DataForSEO paired with zero-cost first-party search data.

## Automated Rank Tracker (`S-01`)

- Keywords are stored in `tracked_keywords` scoped by `project_id`, `device` (`desktop` or `mobile`), `location_code` (e.g. `2840` United States), `language_code`, and `cadence`.
- `php artisan dataforseo:run-rank-batches` submits Standard-queue SERP tasks via `DataForSeoClient::submitStandardSerpTasks()`.
- One SERP response evaluates **both** the project domain and every configured `ProjectCompetitor` simultaneously (`keyword_rank_snapshots`) at zero incremental API cost.
- Snapshots capture `rank_group`, `rank_absolute`, `ranking_url`, `ranking_title`, `has_ai_overview`, `has_featured_snippet`, and structured `serp_features`.

## Keyword Explorer & Competitor Gaps (`S-02`, `S-03`)

- **Keyword Explorer:** Batches up to 700 keywords per `dataforseo_labs/google/keyword_overview/live` call and caches results globally for 30 days in `dataforseo_cache`.
- **Competitor Keyword Gaps:** Calls `domain_intersection` to surface **Missing** (competitors rank top 20, project absent) and **Weak** (competitors outrank project) opportunities, cached for 14 days per competitor set.

## Google Search Console & Bing Overlay (`S-04`)

- First-party credentials are encrypted at rest in `project_integrations` (`credentials_encrypted` cast).
- Nightly Horizon syncs pull query and page clicks, impressions, and CTR while respecting the 4-day search finalization lag.
- Rank Tracker views overlay actual GSC clicks directly beside DataForSEO tracked positions and AI Overview presence.
