# Headless coqui-worker Sidecar

The `coqui-worker` container is a lightweight headless worker service compiled from `coqui-core` and `coqui-crawl` in [`adamgreenwell/coqui`](https://github.com/adamgreenwell/coqui). It runs alongside Laravel Horizon in Coolify to execute:

1. **Scheduled Cloud Audits:** Weekly project crawls bounded by workspace URL tier caps (500 to 5,000 URLs).
2. **Deploy Smoke Checks:** Post-deploy SEO verification checks triggered by Runnr hosting webhooks.
3. **Single-Page Inspections:** SERP top-10 competitor content briefs and active customer backlink watchlist verification.

---

## 1. Runtime Specifications & Resource Bounds

| Property | Value | Description |
| :--- | :--- | :--- |
| **Port** | `8085` | Internal HTTP listener (`COQUI_WORKER_PORT`) |
| **Memory Ceiling** | `< 256MB` | Hard cgroup limit enforced in production compose file |
| **Worker Concurrency** | `4` workers | Prevents outbound socket exhaustion |
| **HTTP Transport** | Reqwest + Rustls | Fast async HTTP client with strict DNS validation |
| **SSRF Filter** | Enabled | Rejects RFC 1918, 127.0.0.0/8, 169.254.169.254, and IPv6 link-local |

---

## 2. API Contract

### `POST /jobs/crawl`
Dispatches a bounded site audit.
```json
{
  "crawlId": "3b2e7c90-...",
  "projectId": 12,
  "seedKind": "url",
  "seed": "https://example.com/",
  "config": {
    "maxDepth": 3,
    "maxUrls": 1000,
    "concurrency": 4,
    "requestTimeoutMs": 15000,
    "sameHostOnly": true,
    "respectRobots": true
  }
}
```

### `POST /jobs/inspect`
Performs targeted inspection on specific URLs.
```json
{
  "urls": ["https://partner.com/reviews/our-app", "https://blog.com/top-tools"],
  "targetDomain": "example.com"
}
```

### `GET /health`
Returns `200 OK` with JSON memory and job queue status:
```json
{
  "status": "healthy",
  "activeJobs": 1,
  "memoryUsageMb": 48.2
}
```

---

## 3. Callback Streaming to Laravel

Progress updates and finalized snapshots stream to Laravel via `POST /api/internal/crawls/callback`:
- Secured with `COQUI_WORKER_TOKEN` bearer authentication.
- Ingested via chunked upserts on `(crawl_run_id, url_hash)`.
- Broadcast in real-time over Laravel Reverb on private channel `project.{projectId}`.
