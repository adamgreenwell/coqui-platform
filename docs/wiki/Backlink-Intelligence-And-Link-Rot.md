# Backlink Intelligence and Link Rot

Coqui SEO combines DataForSEO's global backlink index for discovery with `coqui-worker` for high-frequency verification of a customer's most important earned links.

## Macro Discovery via DataForSEO (`B-01`, `B-02`, `B-04`)

- **Backlink Profile & Referring Domains:** Queries DataForSEO Backlinks `summary`, `referring_domains`, and `anchors` with a 14-day project cache.
- **Crawl Join:** Matches backlink target URLs against the project's latest `crawl_urls` table to surface high-authority 404/301 URLs needing redirect cleanup.
- **Link Intersect:** Compares referring domains across project competitors (`backlinks/domain_intersection`) to find outreach targets linking to multiple rivals.

## Active Backlink Watchlist (`B-03`)

Third-party link indexes can lag weeks behind live site changes. To solve this without building a global link crawler:

1. Customers star 100–500 critical backlinks into `monitored_backlinks` (or import from CSV / DataForSEO).
2. `php artisan coqui:verify-backlinks` runs weekly via `BacklinkVerificationService`.
3. `CoquiWorkerClient::inspectUrls()` fetches **only** those known referring URLs and reports HTTP status, target link presence, `rel` attributes, and page-level `noindex`.
4. Links transition cleanly across states:

| State | Trigger |
| --- | --- |
| `active` | HTTP `< 400`, target link present, page indexable, `rel` unchanged |
| `rel_changed` | Target link changed to `nofollow`, `sponsored`, or `ugc` when `expected_rel` differs |
| `lost` | Target link removed from page or page marked `noindex` |
| `error` | Referring URL returned `4xx`/`5xx` or timed out |
