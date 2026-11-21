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
