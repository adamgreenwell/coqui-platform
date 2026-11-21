# Coqui Desktop ↔ Cloud Sync Contract & Standalone App API Architecture

This contract defines the REST and streaming JSON API exchanged between the **Coqui** desktop application (`crates/coqui-core/src/lib.rs`) and **Coqui SEO Platform** (`api.coquiseo.com/api/v1/*`).

Because `coqui-core` serializes every struct with `#[serde(rename_all = "camelCase")]`, all API requests and responses use `camelCase` field names directly so the desktop app and `coqui-worker` can stream records without translation overhead.

---

## Authentication & Authorization

- **Scheme:** `Authorization: Bearer <sanctum_personal_access_token>`
- **Scoped Token Abilities:**
  - `crawls:sync`: Initialize crawl runs, stream chunks, and finalize crawl snapshots.
  - `crawls:read`: Retrieve historical crawl runs and inspect issue distributions.
  - `projects:read`: Discover workspaces and list projects for targeting.
  - `projects:write`: Register new projects directly from Coqui Desktop.
- **Scoping & RBAC:** Every project route resolves the target `Project` and validates workspace membership (`view` or `update` policy via Laravel Gate).
- **Identifiers:** Every `id` the API returns for users, workspaces and projects is an opaque UUID string, and `{project}` in a URL is that UUID. Crawl runs are identified by their `externalCrawlId` (also returned as `id`), so `{crawlId}` is always that UUID. Sequential database IDs are never accepted (they return `404`) and never returned. The `workspace_id` filter on `GET /api/v1/projects` and `workspaceId` on `POST /api/v1/projects` take the workspace UUID.

---

## Standalone App Endpoints

### 1. Identity & Account Discovery
`GET /api/v1/me`
- Returns current authenticated user and list of accessible workspaces with user roles.

### 2. Project Directory & Registration
- `GET /api/v1/projects`: List accessible projects with latest crawl summary and search filter.
- `POST /api/v1/projects`: Register a new project directly from Coqui Desktop.
- `GET /api/v1/projects/{project}`: Retrieve project detail and crawl statistics.

### 3. Crawl History & Inspection
- `GET /api/v1/projects/{project}/crawls`: Paginated list of historical crawl runs.
- `GET /api/v1/projects/{project}/crawls/{crawlId}`: Crawl details with aggregated issue summary breakdown.

---

## Crawl Synchronization Lifecycle

### 1. Initialize or Upsert Crawl Run
`POST /api/v1/projects/{project}/crawls/sync`

Maps 1:1 to `coqui_core::CrawlSummary` + `coqui_core::CrawlConfig`:

```json
{
  "id": "550e8400-e29b-41d4-a716-446655440000",
  "seed": "https://example.com/",
  "seedKind": "url",
  "status": "completed",
  "config": {
    "maxDepth": 3,
    "maxUrls": 5000,
    "concurrency": 8,
    "requestTimeoutMs": 15000,
    "userAgent": "Coqui/0.1.0 (+https://github.com/adamgreenwell/coqui)",
    "sameHostOnly": true,
    "respectRobots": true,
    "includePatterns": [],
    "excludePatterns": [],
    "authUser": "optional-user",
    "customHeaders": []
  },
  "createdAt": 1795022400000,
  "startedAt": 1795022401000,
  "finishedAt": 1795022460000,
  "totalUrls": 412,
  "source": "desktop"
}
```

> **Security Rule:** Even if a client sends `authPassword` or `Authorization` / `Cookie` values inside `config`, Laravel explicitly strips secrets before persisting `crawl_runs.config`.

### 2. Stream Chunked Records
`POST /api/v1/projects/{project}/crawls/{crawlId}/chunks`

Accepts up to 1,000 `urls`, 2,500 `links`, and 2,500 `issues` per request; larger chunks get a `422`. Every record is upserted, so an interrupted upload can resend any chunk and end up with the same rows:
- URLs on `(crawl_run_id, url)`
- issues on `(crawl_run_id, url, rule)`
- links on `(crawl_run_id, fromUrl, toUrl, anchor, rel)`

A run that has been finalized with any status other than `paused` is sealed: further chunks get a `409`. A paused desktop run accepts chunks again when the crawl resumes. Imported runs can be re-synced to reopen a finalized snapshot (see below).

The endpoints below address both client sources (`desktop` and `import`); a server-side run (`worker`, `deploy_smoke`) is never addressable through them and returns `404`.

```json
{
  "urls": [
    {
      "id": 1,
      "crawlId": "550e8400-e29b-41d4-a716-446655440000",
      "url": "https://example.com/",
      "status": 200,
      "contentType": "text/html; charset=utf-8",
      "title": "Example Home",
      "metaDescription": "Example description",
      "h1": "Welcome",
      "h1Count": 1,
      "canonical": "https://example.com/",
      "metaRobots": "index, follow",
      "redirectTarget": null,
      "redirectChain": [],
      "wordCount": 642,
      "depth": 0,
      "responseTimeMs": 145,
      "sizeBytes": 28412,
      "error": null,
      "discoveredAt": 1795022401200
    }
  ],
  "links": [
    {
      "fromUrl": "https://example.com/",
      "toUrl": "https://example.com/pricing",
      "anchor": "Pricing",
      "rel": null,
      "internal": true
    }
  ],
  "issues": [
    {
      "crawlId": "550e8400-e29b-41d4-a716-446655440000",
      "url": "https://example.com/about",
      "rule": "missing_meta_description",
      "severity": "warning",
      "detail": null
    }
  ]
}
```

### 3. Finalize Sync
`POST /api/v1/projects/{project}/crawls/{crawlId}/finalize`

Accepts optional `issueCounts` (`Array<{ rule, severity, count }>` matching `coqui_core::IssueCount`; `severity` is `error`, `warning` or `opportunity`). It seals the `CrawlRun` status and sets the error, warning and opportunity totals from `issueCounts`, or counts the uploaded issues when it's omitted. Finalizing again is allowed and recomputes the totals.

It then broadcasts `CrawlSynced` on the project's private channel over Reverb:

```json
{
  "project_id": 12,
  "external_crawl_id": "3b2e7c90-...",
  "status": "completed",
  "total_urls": 1116,
  "error_count": 4,
  "warning_count": 37,
  "opportunity_count": 12,
  "source": "desktop",
  "finalized_at": "2026-11-21T05:30:00+00:00"
}
```

---

## Imported Crawls (Screaming Frog)

`POST /api/v1/projects/{project}/crawls/sync` accepts `"source": "import"` in
addition to `"source": "desktop"`. Any other value (including the server-side
`worker` and `deploy_smoke` sources) is a `422`; those runs are created by the
platform and cannot be forged with a desktop token.

- **No issues.** An import sends URLs and links only. Do not upload issue rows
  and do not map Screaming Frog rules; the cloud does not derive issues for an
  import.
- **Ownership.** A crawl ID can only be re-synced by the same project and the
  same source. Re-using an ID from another project, or re-syncing an imported
  ID as `desktop` (or vice versa), is a `409`.
- **Re-importing is idempotent.** Re-syncing a finalized imported crawl reopens
  it (`finalizedAt` becomes `null`) so chunks are accepted again, and the next
  finalize recomputes the same totals. The project keeps exactly one crawl run
  for that `externalCrawlId`. Re-finalizing without a preceding sync stays
  allowed and recomputes totals.
- **Sealing** is unchanged: once finalized, chunks return `409` until the run is
  reopened (by a paused-desktop resume or by re-syncing an import).
- **Not an audit.** Imported runs stay visible in crawl history and can be used
  as either side of a crawl comparison, but they are not the project's "latest
  crawl": they are excluded from latest-audit summaries and do not advance
  `lastCrawledAt`/crawl cadence. The project overview exposes them separately
  and shows an "Imported" badge.

### Crawl Comparison Measurement

`GET /api/v1/projects/{project}/crawls/compare` always reports URL, status,
metadata and redirect diffs. Issue deltas are measured only when both runs are
real audits:

- `issueMeasurement`: `"measured"` or `"not_measured"`.
- `summary.baseline.issuesMeasured` / `summary.target.issuesMeasured`: whether
  that side carries issue data.
- When either side is imported: `summary.delta.newIssuesCount`,
  `resolvedIssuesCount` and `persistentIssuesCount` are `null` (not `0`), and
  `issueDeltas.rules`, `issueDeltas.newIssues` and `issueDeltas.resolvedIssues`
  are empty. URL and status diffs remain populated.

This applies to the authenticated comparison, the shared public diff page, and
the standalone API.

---
