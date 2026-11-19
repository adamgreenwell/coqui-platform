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

Accepts up to 1,000 `urls`, 2,500 `links`, and 2,500 `issues` per request, using upsert semantics on `(crawl_run_id, url)` so interrupted uploads can retry safely.

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

Accepts optional `issueCounts` (`Array<{ rule, severity, count }>` matching `coqui_core::IssueCount`), seals the `CrawlRun` status, recomputes aggregate totals in PostgreSQL if omitted, and broadcasts `CrawlSynced` over Reverb.
