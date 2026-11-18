# Coqui SEO Platform — Architecture & Development Plan

**Coqui SEO Platform** (`coquiseo.com` / `app.coquiseo.com`) is the cloud SEO, SERP intelligence, and hybrid crawl platform built with **Laravel 13** to complement the [Coqui](https://github.com/adamgreenwell/coqui) Rust/Tauri desktop crawler and integrate natively with the Runnr hosting platform.

- **Backlog:** 6 epics and 24 feature issues tracked on the Coqui SEO Platform GitHub Project board.
- **Stack:** Laravel 13 (PHP 8.4), Inertia v3 + React 19, PostgreSQL 17, Redis + Laravel Horizon, Laravel Reverb, Sanctum, and a headless Rust `coqui-worker` sidecar.
- **MVP Infrastructure:** Bare-metal server running Coolify via Docker Compose, transitioning to horizontally scaled worker/DB nodes at public launch.

---

## 1. Strategic Build-vs-Buy Architecture

Building a global web-scale link and SERP index from scratch requires petabytes of storage, distributed proxy fleets, and continuous worldwide crawling. Coqui SEO avoids that fixed infrastructure trap by splitting the web into two layers:

| Layer | Powered By | Role | Economics |
| --- | --- | --- | --- |
| **Macro Web** | **DataForSEO v3 APIs** | Global SERPs, search volume, keyword difficulty, competitor gaps, domain backlink discovery | Variable COGS, aggressively cached globally & queued via Standard postbacks |
| **Micro Web (Desktop)** | **Coqui Desktop** (Tauri + Rust) | Deep, zero-marginal-cost technical crawls run on the user's hardware, synced to cloud | $0 server crawl cost |
| **Micro Web (Cloud)** | **`coqui-worker`** (`coqui-core`) | Scheduled light audits, live SERP top-10 scraping, watchlist backlink verification, deploy checks | Bounded single-binary Rust worker |
| **First-Party & Edge** | **GSC, Bing, GA4, Plausible, Runnr** | Real clicks/impressions, traffic attribution, post-deploy smoke tests, server-log bot tracking | $0 API cost + unique hosting moat |

---

## 2. Coolify Service Topology (MVP Bare-Metal)

```text
                        ┌─────────────────────────┐
                        │   coquiseo.com / app.   │
                        │  Nginx + PHP 8.4-FPM    │
                        └────────────┬────────────┘
                                     │
        ┌────────────────────────────┼────────────────────────────┐
        ▼                            ▼                            ▼
┌───────────────┐          ┌───────────────────┐        ┌───────────────────┐
│ PostgreSQL 17 │          │ Redis 7 + Horizon │        │  Laravel Reverb   │
│ (JSONB/Citus) │          │  + Scheduler      │        │   (WebSockets)    │
└───────────────┘          └─────────┬─────────┘        └───────────────────┘
                                     │
                 ┌───────────────────┴───────────────────┐
                 ▼                                       ▼
     ┌───────────────────────┐               ┌───────────────────────┐
     │   DataForSEO v3 API   │               │ coqui-worker (Rust)   │
     │ Standard Postbacks +  │               │ Shared coqui-core     │
     │  Shared Cache Layer   │               │ Targeted Cloud Audits │
     └───────────────────────┘               └───────────────────────┘
```

---

## 3. Laravel Domain Boundaries

```text
app/Domains/
├── Projects/       # Workspaces, memberships, sites, competitors, Sanctum desktop tokens
├── Serp/           # DataForSeoClient, SpendGuard, CacheRepository, RankTracker, Keywords
├── Crawls/         # Desktop CrawlSyncPayload ingest, CrawlRun diffs, CoquiWorkerClient
├── Backlinks/      # DataForSEO backlink summaries + active watchlist link-rot checks
└── Integrations/   # GSC, Bing, GA4, Plausible, Runnr deploy webhooks, server bot telemetry
```

---

## 4. DataForSEO Cost Controls & Unit Economics

1. **Sandbox First:** `DATAFORSEO_API_BASE_URL` defaults to `https://sandbox.dataforseo.com` in non-production environments.
2. **Scheduled Spend Kill-Switch:** Automated batch purchases require `DATAFORSEO_SCHEDULED_SPEND=true` explicitly.
3. **Standard Queue + Postbacks:** Rank tracking uses DataForSEO Standard queues with `/api/webhooks/dataforseo` postbacks rather than expensive Live calls.
4. **Shared Cross-Tenant Cache:** Keyword overview/volume (`30d` TTL) and domain summaries (`14d` TTL) are cached by normalized signature so duplicate lookups across users cost $0.
5. **Spend Guard Ledger:** Every request estimates price beforehand, checks daily/monthly caps and workspace quotas, and reconciles exact vendor-reported `cost` fields.

---

## 5. Phased MVP Roadmap

### Phase 1 - Foundation & DataForSEO Core

| ID | Feature | Epic | Priority | Size | Area |
| --- | --- | --- | --- | --- | --- |
| F-01 | Laravel 13 & Coolify Docker Compose stack | E01 | P0 | L | coolify |
| F-02 | Workspaces, RBAC, and Sanctum desktop auth | E01 | P0 | M | platform |
| F-03 | Project and competitor registry | E01 | P0 | M | platform |
| F-04 | Reverb realtime event stream & activity log | E01 | P1 | S | platform |
| D-01 | DataForSEO v3 HTTP client, sandbox toggle & archive | E02 | P0 | L | dataforseo |
| D-02 | Global cross-project DataForSEO cache repository | E02 | P0 | M | dataforseo |
| D-03 | DataForSEO Spend Guard & daily/monthly budget caps | E02 | P0 | M | dataforseo |
| D-04 | Standard-queue task postback webhook & collector | E02 | P0 | L | dataforseo |

### Phase 2 - Rank Tracking & Keyword Intelligence

| ID | Feature | Epic | Priority | Size | Area |
| --- | --- | --- | --- | --- | --- |
| S-01 | Automated multi-device rank tracker & SERP feature parser | E03 | P0 | XL | serp |
| S-02 | Keyword Explorer & search volume batching | E03 | P0 | L | serp |
| S-03 | Competitor keyword gap & domain intersection | E03 | P1 | L | serp |
| S-04 | Google Search Console & Bing Webmaster first-party overlay | E03 | P1 | L | integrations |

### Phase 3 - Hybrid Crawler & Desktop Sync

| ID | Feature | Epic | Priority | Size | Area |
| --- | --- | --- | --- | --- | --- |
| C-01 | Coqui Desktop sync API & chunked snapshot ingestion | E04 | P0 | XL | crawler |
| C-02 | Historical crawl comparison & technical diff viewer | E04 | P1 | L | crawler |
| C-03 | Headless coqui-worker Rust container & Redis job bridge | E04 | P1 | XL | crawler |
| C-04 | Live SERP Top-10 content brief & competitor page scraper | E04 | P1 | L | crawler |

### Phase 4 - Backlink Intelligence & Link-Rot Monitor

| ID | Feature | Epic | Priority | Size | Area |
| --- | --- | --- | --- | --- | --- |
| B-01 | Backlink profile overview & referring domains explorer | E05 | P1 | L | backlinks |
| B-02 | New & lost backlinks timeline | E05 | P2 | M | backlinks |
| B-03 | Active backlink watchlist & link-rot monitor | E05 | P1 | L | backlinks |
| B-04 | Competitor link intersect & broken backlink reclaim | E05 | P2 | M | backlinks |

### Phase 5 - Hosting Platform & Analytics Moat

| ID | Feature | Epic | Priority | Size | Area |
| --- | --- | --- | --- | --- | --- |
| H-01 | Runnr hosting post-deploy SEO smoke tests | E06 | P1 | L | integrations |
| H-02 | Server access log bot & AI crawler telemetry | E06 | P1 | L | integrations |
| H-03 | GA4 & Plausible organic traffic attribution | E06 | P2 | M | integrations |
| H-04 | Subscription billing, plan tiers & usage metering | E06 | P2 | L | platform |

See [roadmap-matrix.csv](reference/roadmap-matrix.csv) for machine-readable dependencies and [desktop-sync-contract.md](desktop-sync-contract.md) for the exact `coqui-core` JSON sync schema.
