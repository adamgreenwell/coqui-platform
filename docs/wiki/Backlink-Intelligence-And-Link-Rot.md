# Backlink Intelligence and Link Rot

Coqui SEO combines DataForSEO's global backlink index for discovery with `coqui-worker` for high-frequency verification of a customer's most important earned links.

## Macro Discovery via DataForSEO (`B-01`, `B-02`, `B-04`)

- **Backlink Profile & Referring Domains:** Queries DataForSEO Backlinks `summary`, `referring_domains`, and `anchors` with a 14-day project cache.
- **Crawl Join:** Matches backlink target URLs against the project's latest `crawl_urls` table to surface high-authority 404/301 URLs needing redirect cleanup.
- **Link Intersect:** Compares referring domains across project competitors (`backlinks/domain_intersection`) to find outreach targets linking to multiple rivals.

## Active Backlink Watchlist (`B-03`)

Third-party link indexes can lag weeks behind live site changes. To solve this without building a global link crawler:

1. Customers star critical backlinks into `monitored_backlinks` (or import from CSV / DataForSEO), up to `coqui.backlinks.max_monitored_links` per project (500 by default). Imports past the cap skip the extra rows and say how many.
2. `php artisan coqui:verify-backlinks` runs weekly and queues `VerifyMonitoredBacklinksJob` per project on the `backlinks` queue (`--sync` runs it inline). Each run checks up to 50 links within a time budget and queues the rest, so no run outlives the queue's retry window. **Verify all** on the watchlist queues the same job.
3. `CoquiWorkerClient::inspectUrls()` fetches **only** those known referring URLs, up to 200 per request, and reports HTTP status, target link presence, `rel`, anchor text, and page-level `noindex`. A URL the SSRF guard refuses is left out of the batch (and marked `error`) instead of failing it.
4. Links the worker couldn't settle are fetched directly, spread across hosts and at least `coqui.backlinks.verify_host_delay_ms` (1 s) apart per host.
5. A state change broadcasts `BacklinkAlertTriggered`; a broadcast failure (Reverb down) is reported and skipped, never stopping the run.
6. Links transition cleanly across states:

| State | Trigger |
| --- | --- |
| `active` | HTTP `< 400`, target link present, page indexable, `rel` unchanged |
| `rel_changed` | Target link changed to `nofollow`, `sponsored`, or `ugc` when `expected_rel` differs |
| `anchor_changed` | Target link's text no longer matches `expected_anchor` (ignoring case and spacing); the text seen is kept in `last_observed_anchor` |
| `lost` | Target link removed from page or page marked `noindex` |
| `error` | Referring URL returned `4xx`/`5xx` or timed out |

## History Timeline, Alerts and Weekly Digest

- **Daily history:** The timeline stores one `backlink_history_snapshots` row per day from DataForSEO `timeseries_summary` (totals, rank) and `timeseries_new_lost_summary` (new and lost backlinks and referring domains), both with `group_range: day`. Velocity totals are summed from those rows; the new/lost domain lists on the page are examples from the cached referring domains.
- **No paid calls on repeat views:** History is fetched on the first view by a member who can spend, or by **Refresh**. Every attempt (even an empty or failed one) sets `projects.backlink_history_checked_at`, and an empty history is fetched again only after `coqui.backlinks.history_recheck_days` (7, env `BACKLINK_HISTORY_RECHECK_DAYS`). Read-only members never trigger a fetch.
- **Alerts:** `php artisan backlinks:evaluate-alerts` runs daily at 05:40. It checks stored data only (every snapshot within the recheck window for a sudden drop, cached referring domains for a spam spike) and stores each event once in `backlink_alerts` (unique per project, type and `dedupe_key`: the drop date, or a hash of the spammy domains). `BacklinkAlertTriggered` is broadcast only when a new row is stored. Viewing the page only reads stored alerts. With `DATAFORSEO_SCHEDULED_SPEND=true` the command first refreshes histories older than the recheck window (2 requests per project, estimated at $0.02 each).
- **Weekly digest:** `php artisan backlinks:send-digests` runs Mondays at 07:00 (`--force` resends). It covers the seven full days before the run (so consecutive digests never share a day): alerts, new and lost totals, and watchlist link changes:
  - by email to members who subscribed on the Backlinks page (any member can subscribe themselves; members who lost access are skipped);
  - to the project's Slack incoming webhook, which editors set on the same page. Only `https://hooks.slack.com/services/...` URLs are accepted, the URL is stored encrypted and never sent to the browser, and the post has a 5-second timeout with redirects off.
  A project gets at most one digest every 6 days, so a repeated run sends nothing.
