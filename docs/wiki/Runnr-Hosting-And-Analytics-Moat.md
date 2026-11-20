# Runnr Hosting and Analytics Moat

Connecting Coqui SEO directly to the Runnr hosting platform creates capabilities standalone SEO SaaS tools cannot offer without external crawling or JS snippet bloat.

## Post-Deploy SEO Smoke Tests (`H-01`)

- Projects linked to Runnr via `projects.runnr_site_id` expose `POST /api/webhooks/runnr/deploy`.
- `RunnrDeployWebhookController` validates the HMAC-SHA256 `X-Runnr-Signature` header (`RUNNR_WEBHOOK_SECRET`).
- `RunnrDeployWebhookHandler` creates a `CrawlRun` (`source = 'deploy_smoke'`) and dispatches a bounded 100-URL crawl (`maxDepth = 2`) to `coqui-worker`.
- Smoke checks catch accidental `Disallow: /` in `robots.txt`, staging `noindex` tags leaked to production, 5xx templates, and canonical domain regressions immediately after deploy.

## Server Access-Log Bot & AI Crawler Telemetry (`H-02`)

- Hosted sites ship hourly aggregated bot hit summaries into `bot_crawl_logs` grouped by `bot_family`, `status_bucket` (`200`, `300`, `400`, `500`), `hit_count`, `bytes_served`, and `top_error_paths`.
- Tracks traditional search engines (`googlebot`, `bingbot`) alongside AI crawlers (`gptbot`, `claudebot`, `perplexitybot`) to highlight wasted crawl budget on 404s and redirect chains.

## Traffic Attribution & Billing Tiers (`H-03`, `H-04`)

- **GA4 & Plausible Attribution:** Overlays organic sessions and AI-assistant referrals onto crawled URLs by normalized path.
- **Metered Tiers:** Laravel Pennant and Cashier align workspace quotas (`tracked_keyword_limit`, `cloud_crawl_url_limit`, `monthly_dfs_budget_usd`) with DataForSEO COGS, while the Runnr add-on or the Agency plan unlocks native deploy hooks and bot telemetry. Linking a project to a Runnr site does not unlock them on its own, because Coqui can't yet verify who owns a Runnr site ID.
