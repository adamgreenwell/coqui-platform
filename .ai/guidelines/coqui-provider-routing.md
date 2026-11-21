## SEO data provider routing and spend

Paid SEO data comes from three providers. Every paid call goes through that provider's client and spend guard. Never call a provider's HTTP API directly from a controller or job.

| Data | Provider | Entry point |
| --- | --- | --- |
| Keyword metrics (bulk screening) | SE Ranking for accepted ASCII keywords; DataForSEO `keyword_overview` for the rest, or when SE Ranking isn't configured | `Serp\Services\SeoProviderRouter::lookupKeywords()` |
| Keyword Explorer, Keyword Gap, content briefs | DataForSEO | `KeywordResearchService`, `KeywordGapService`, `ContentBriefService` |
| Rank tracking (SERP) | DataForSEO standard queue with postbacks; `dataforseo:collect-tasks` collects anything the postback missed | `RunRankBatchesCommand`, `DataForSeoClient` |
| Backup rank checks for failed or stalled tasks | SERPHouse | `Serp\RankFallback\*` (`rank:fallback`) |
| Backlinks (profile, history, intersect) | DataForSEO | `Backlinks\Services\*` |

Spend rules:
- `DataForSeoSpendGuard::assertCanSpend($cost, $workspace, $isScheduled)` enforces the global daily and monthly caps and the workspace's `monthly_dfs_budget_usd`. SE Ranking and the SERPHouse fallback have their own guards, and the fallback shares the workspace allowance.
- Anything that runs from the scheduler must pass `isScheduled: true`. Scheduled spend is refused unless `DATAFORSEO_SCHEDULED_SPEND` (or `SERANKING_SCHEDULED_SPEND`) is on.
- Read-only paths run inside `ReadOnlySpendScope::run()`, where every guard refuses new paid requests and only cache hits are served.
- Prefer cached data: responses are cached in `dataforseo_cache` through `DataForSeoCacheRepository`, with TTLs in `config/coqui.php`. Cache "no data" results too, so a page view never repeats a paid lookup. Only cache a result when every task in the response succeeded.
- Add new per-request prices to `config/coqui.php` under `dataforseo.prices` and pass them as the estimated cost.
- `ProviderNotConfiguredException::throwUnlessSeoDataConfigured()` turns a missing credential into a clear user-facing message.
- User-facing copy never names a provider.
