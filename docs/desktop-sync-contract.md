# Coqui Desktop ↔ Cloud Sync Contract

This contract defines the JSON payloads exchanged between the **Coqui** desktop application (`crates/coqui-core/src/lib.rs`) and **Coqui SEO Platform** (`api.coquiseo.com/api/v1/crawls/*`).

Because `coqui-core` serializes every struct with `#[serde(rename_all = "camelCase")]`, all API requests use `camelCase` field names directly so the desktop app and `coqui-worker` can stream records without translation overhead.

---

## Authentication

- **Scheme:** `Authorization: Bearer <sanctum_personal_access_token>`
- **Required Token Ability:** `crawls:sync`
- **Scoping:** Every sync route resolves the target `Project` and authorizes workspace membership (`update` policy).

---

## Lifecycle Endpoints

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
