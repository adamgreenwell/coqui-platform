# Runnr Hosting and Analytics Moat

Connecting Coqui SEO directly to the Runnr hosting platform creates capabilities standalone SEO SaaS tools cannot offer without external crawling or JS snippet bloat.

## Post-Deploy SEO Smoke Tests (`H-01`)

- Projects linked to Runnr via `projects.runnr_site_id` expose `POST /api/webhooks/runnr/deploy`.
- `RunnrDeployWebhookController` checks two headers, keyed by `RUNNR_WEBHOOK_SECRET`:
  - `X-Runnr-Timestamp`: Unix seconds when the delivery was signed. Deliveries more than `RUNNR_WEBHOOK_TOLERANCE_SECONDS` (default 300) away from now are refused with 401, so a captured request can't be replayed later.
  - `X-Runnr-Signature`: hex HMAC-SHA256 of `"{timestamp}.{raw body}"`. A missing timestamp is a 401, a wrong signature a 403.
- Result callbacks from Coqui to Runnr's `callback_url` are signed the same way, with the same two headers.
- `RunnrDeployWebhookHandler` creates a `CrawlRun` (`source = 'deploy_smoke'`) and dispatches a bounded 100-URL crawl (`maxDepth = 2`) to `coqui-worker`. A redelivered `deploy_id` returns the existing run instead of starting another crawl.
- The smoke crawl ignores `robots.txt`, so a deploy that blocks crawlers still gets its pages checked. `robots.txt` and the XML sitemap are fetched directly and judged on their own:
  - zero URLs crawled fails the check in any environment;
  - `Disallow: /` for `*` or Googlebot fails it on production;
  - a `robots.txt` change since the last smoke run is a low-severity note;
  - on production, a sitemap that was valid last time and is now missing fails the check, and one that dropped below half its previous size (from at least 10 entries) is a warning.
- Page checks still catch staging `noindex` tags leaked to production, 5xx templates, and canonical domain regressions immediately after deploy.

## Server Access-Log Bot & AI Crawler Telemetry (`H-02`)

- Hosted sites ship hourly aggregated bot hit summaries into `bot_crawl_logs` grouped by `bot_family`, `status_bucket` (`200`, `300`, `400`, `500`), `hit_count`, `bytes_served`, and `top_paths`.
- **Batches are idempotent.** Each push may carry a `batchId`, and rows are stored under it (or, without one, under a hash of the payload). A resent batch replaces its own rows, so a retry never counts twice, while different batches for the same hour add up. The response says `duplicate: true` for a resend, and the Googlebot 404 alert fires only for the first copy.
- **Per-entry detail:** `topPaths` (path, hits, optional exact `status`, `redirectTarget`, `hops`) for any status, plus an optional `parameterHitCount`. The older `topErrorPaths` is still accepted. A path's status defaults to the entry's `statusBucket` when that is an exact code (for example `404`).
- **Crawl-budget waste** = redirect hits (3xx) + error hits (4xx/5xx) + parameterized-URL hits that returned 2xx. The dashboard lists top crawled paths, parameter URLs, and redirect chains (more than one hop, or a redirect to a URL that itself redirects). The 404 remediation list and alert cover only `404` and `410`, not every 4xx.
- **Monthly partitions (Postgres).** `bot_crawl_logs` is range-partitioned by month on `bucket_hour`. `php artisan telemetry:ensure-partitions` runs daily at 01:15 and keeps three months ahead. Rows outside every month land in `bot_crawl_logs_default`, and are moved out when their month is created.
- Tracks traditional search engines (`googlebot`, `bingbot`) alongside AI crawlers (`gptbot`, `claudebot`, `perplexitybot`) to highlight wasted crawl budget on 404s and redirect chains.

## Traffic Attribution & Billing Tiers (`H-03`, `H-04`)

- **GA4 & Plausible Attribution:** Overlays organic sessions and AI-assistant referrals onto crawled URLs by normalized path.
- **Metered Tiers:** Laravel Pennant and Cashier align workspace quotas (`tracked_keyword_limit`, `cloud_crawl_url_limit`, `monthly_dfs_budget_usd`) with DataForSEO COGS, while the Runnr add-on or the Agency plan unlocks native deploy hooks and bot telemetry. Linking a project to a Runnr site does not unlock them on its own, because Coqui can't yet verify who owns a Runnr site ID.
