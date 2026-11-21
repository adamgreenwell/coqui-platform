# coqui-worker

The headless crawler Laravel sends cloud crawls and backlink checks to. It's a
small Rust service in this directory that reuses the crawl engine from
[`adamgreenwell/coqui`](https://github.com/adamgreenwell/coqui) (`coqui-core`,
`coqui-store`, `coqui-crawl`, `coqui-audit`), pinned to one commit in
`Cargo.toml`.

It does three things:

1. **Cloud crawls** (scheduled audits, "Run cloud crawl", deploy smoke checks):
   crawl the site, run the audit rules, and post URLs, links and issues back to
   Laravel.
2. **Backlink checks**: fetch pages that link to a project and report whether the
   link is still there, its `rel`, and whether the page is noindex.
3. **Health**: `GET /health` for Docker and Coolify.

## Running it

### With docker compose (local)

Set a token in `.env` (any long random string; Laravel and the worker share it):

```
COQUI_WORKER_TOKEN=change-me-to-a-long-random-string
```

Then start the stack with the worker profile:

```
docker compose --profile worker up -d --build
```

`--build` compiles the image from this directory (a few minutes the first
time). Without it, compose pulls `ghcr.io/adamgreenwell/coqui-worker:latest`.
The container reports healthy once `/health` answers.

### The published image

`.github/workflows/coqui-worker.yml` tests the crate on every change and, on
`main`, publishes `ghcr.io/adamgreenwell/coqui-worker:latest` and
`:sha-<commit>` for `linux/amd64`. On other architectures (Apple Silicon),
build locally with `--build`.

### Building by hand

```
docker build -t coqui-worker docker/coqui-worker
```

To try a different `coqui` commit without editing `Cargo.toml`:

```
docker build --build-arg COQUI_CORE_REV=<commit sha> -t coqui-worker docker/coqui-worker
```

To move the pin for good, change the four `rev = "..."` values in `Cargo.toml`
and run `cargo update -p coqui-core` so `Cargo.lock` follows.

## Configuration

| Variable | Default | |
| :--- | :--- | :--- |
| `COQUI_WORKER_TOKEN` | (required) | Shared with Laravel. Jobs need `Authorization: Bearer <token>`, and the worker sends it on callbacks. The worker won't start without it. |
| `COQUI_CALLBACK_URL` | (required) | Laravel's `/api/internal/crawls/callback`. In compose: `http://nginx/api/internal/crawls/callback`. |
| `COQUI_WORKER_PORT` | `8085` | Listen port. |
| `COQUI_MAX_CRAWLS` | `1` | Crawls run at the same time. Others wait in a queue. |
| `COQUI_MAX_QUEUED` | `20` | Queue length; past it, new crawls are refused with 503 and the run fails at once. |
| `COQUI_MAX_URLS` | `10000` | Upper bound on any crawl, whatever Laravel asks for. |
| `COQUI_MAX_DEPTH` | `10` | Upper bound on crawl depth. |
| `COQUI_CONCURRENCY` | `4` | Upper bound on concurrent requests per crawl. |
| `COQUI_USER_AGENT` | coqui default | User agent for crawls and checks. |
| `COQUI_INSPECT_DEADLINE_SECONDS` | `25` | A backlink check answers by then with what finished (Laravel's client times out at 30s and checks the rest itself). |
| `RUST_LOG` | `info` | Log level. |

Laravel's side is `COQUI_WORKER_URL` (default `http://coqui-worker:8085`),
`COQUI_WORKER_TOKEN`, `COQUI_WORKER_CONNECT_TIMEOUT` (5s) and
`COQUI_WORKER_STALL_MINUTES` (30).

## Memory

Each crawl writes to its own SQLite file under `/tmp` and the file is deleted
when the crawl ends, so memory doesn't grow with the size of the crawl the way
an in-memory crawl would. With one crawl at a time, the container stays inside
compose's 256MB limit on a few-thousand-URL crawl. Raise `COQUI_MAX_CRAWLS`
only with more memory.

## Network safety

The worker fetches pages on customer sites from inside the same network as the
database, Redis and the app. Every request a crawl or a backlink check makes,
including each redirect hop, may only reach a public address. Loopback, private
ranges, link-local (cloud metadata), carrier-grade NAT and other containers are
refused. Crawls go through a small filtering proxy inside the worker, since the
crawl engine builds its own HTTP client. DNS answers are checked and then
pinned, so a hostname can't pass the check and then resolve somewhere else.

## API

All `/jobs/*` routes need `Authorization: Bearer $COQUI_WORKER_TOKEN`.

### `POST /jobs/crawl`

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

Answers `202 {"accepted": true, "crawlId": "..."}` and crawls in the
background. Repeating a `crawlId` that's still queued or running answers 202
again without starting a second crawl. `422` means the request was invalid (for
example a seed on a private address), and `503` means the queue is full or the
worker is shutting down.

### `POST /jobs/inspect`

```json
{ "urls": ["https://partner.com/reviews/our-app"], "targetDomain": "example.com" }
```

Up to 200 URLs. Answers:

```json
{
  "results": [
    {
      "url": "https://partner.com/reviews/our-app",
      "status": 200,
      "hasTargetLink": true,
      "observedRel": "dofollow",
      "noindex": false
    }
  ]
}
```

`observedRel` is one of `nofollow`, `sponsored`, `ugc`, `dofollow`, taken from
the first link to the target domain or a subdomain of it. A URL that couldn't
be fetched has `status: null` and an `error`. URLs not finished by the deadline
are left out.

### `GET /health`

No token needed.

```json
{ "status": "healthy", "activeJobs": 1, "queuedJobs": 0, "memoryUsageMb": 48.2 }
```

## Callbacks to Laravel

The worker posts to `COQUI_CALLBACK_URL` with the same token:

- while crawling: `{"crawlId", "status": "running", "crawledUrls", "totalUrls"}`
  every few seconds when progress changes, and at least once a minute
  regardless (Laravel marks a run failed after `COQUI_WORKER_STALL_MINUTES` of
  silence);
- after the crawl and audit: URL rows in batches of 500, then links and
  issues in batches of 2,500, each with `"status": "running"`;
- last: `{"crawlId", "status": "completed"}`, or `"failed"` if the crawl
  failed or the worker is shutting down.

Network errors, 429 and 5xx are retried with backoff. A 404 or 409 (the run is
unknown or already closed) stops the crawl.

## Development

```
cd docker/coqui-worker
cargo test
cargo clippy --all-targets -- -D warnings
COQUI_WORKER_TOKEN=dev COQUI_CALLBACK_URL=http://localhost:8080/api/internal/crawls/callback cargo run
```
