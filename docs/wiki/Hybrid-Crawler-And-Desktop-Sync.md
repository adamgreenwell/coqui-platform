# Hybrid Crawler and Desktop Sync

Coqui SEO Platform runs a **hybrid crawl architecture** so large technical crawls use zero cloud compute when initiated on the user's machine, while light scheduled checks and competitor page extractions run automatically in the cloud.

## Shared Core (`coqui-core`)

All crawl configuration, URL normalization, HTML extraction, and SEO audit rules live once in the Rust `coqui-core` workspace in `adamgreenwell/coqui`.

- **Coqui Desktop (Tauri 2):** Runs `coqui-core` + `coqui-crawl` + `coqui-audit` locally against SQLite, then syncs completed snapshots to `coqui-platform`.
- **`coqui-worker` (Cloud Sidecar):** Compiles the same Rust crates into a headless HTTP worker container running alongside Laravel in Coolify (`COQUI_WORKER_URL`).

## Desktop Sync API (`/api/v1/projects/{project}/crawls/*`)

All sync endpoints require a Sanctum token with ability `crawls:sync` and pass `ProjectPolicy::update`. Payloads match `coqui-core`'s `#[serde(rename_all = "camelCase")]` serialization 1:1 (see [Desktop Sync Contract](../desktop-sync-contract.md)):

1. **`POST /api/v1/projects/{project}/crawls/sync`** — Upserts a `CrawlRun` from `CrawlSummary` + `CrawlConfig`.
2. **`POST /api/v1/projects/{project}/crawls/{crawlId}/chunks`** — Streams transactional batches (up to 1,000 `UrlRecord`s, 2,500 `LinkRecord`s, and 2,500 `Issue`s per request) using idempotent upserts on `(crawl_run_id, url_hash)`.
3. **`POST /api/v1/projects/{project}/crawls/{crawlId}/finalize`** — Computes final URL and issue severity totals (`error`, `warning`, `opportunity`) and updates `projects.last_crawled_at`.

### Secret Scrubbing

`CrawlSyncPayload::scrubConfigSecrets()` runs before any `crawl_runs.config` payload is written:

- Deletes `authPassword` completely.
- Replaces non-empty `authUser` with `[redacted]`.
- Redacts values for `Authorization`, `Cookie`, `Proxy-Authorization`, and `X-Api-Key` inside `customHeaders`.

## Cloud Worker Jobs (`CoquiWorkerClient`)

Laravel dispatches two bounded job types to `coqui-worker`:

- **`POST /jobs/crawl`** — Weekly site audits (capped by `workspace.cloud_crawl_url_limit`) and post-deploy Runnr smoke checks (`config('coqui.worker.smoke_max_urls')`).
- **`POST /jobs/inspect`** — Single-page fetches for SERP top-10 content briefs and active backlink verification.
