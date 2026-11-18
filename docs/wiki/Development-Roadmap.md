# Development Roadmap

Work is organized into **6 Epics** and **24 Feature Issues** tracked on [GitHub Project #17](https://github.com/users/adamgreenwell/projects/17/views/1?layout_template=board) and generated from `tools/plan/*.mjs`.

## Epics Overview

| Epic | Phase | Priority | Title |
| --- | --- | --- | --- |
| `E01` | Phase 1 | P0 | Laravel 13 Foundation, Workspaces & Coolify Stack |
| `E02` | Phase 1 | P0 | DataForSEO Client, Shared Cache & Spend Guard |
| `E03` | Phase 2 | P0 | Rank Tracking, Keyword Explorer & GSC/Bing Overlay |
| `E04` | Phase 3 | P1 | Coqui Desktop Sync & `coqui-worker` Cloud Crawler |
| `E05` | Phase 4 | P1 | Backlink Intelligence & Active Link-Rot Watchlist |
| `E06` | Phase 5 | P1 | Runnr Hosting Webhooks, Bot Telemetry & Traffic Attribution |

## Feature Matrix by Phase

### Phase 1 — Foundation & DataForSEO Core
- `F-01` Laravel 13 & Coolify Docker Compose stack
- `F-02` Workspaces, RBAC, and Sanctum desktop auth
- `F-03` Project and competitor registry
- `F-04` Reverb realtime event stream & activity log
- `D-01` DataForSEO v3 HTTP client, sandbox toggle & archive
- `D-02` Global cross-project DataForSEO cache repository
- `D-03` DataForSEO Spend Guard & daily/monthly budget caps
- `D-04` Standard-queue task postback webhook & collector

### Phase 2 — Rank Tracking & Keyword Intelligence
- `S-01` Automated multi-device rank tracker & SERP feature parser
- `S-02` Keyword Explorer & search volume batching
- `S-03` Competitor keyword gap & domain intersection
- `S-04` Google Search Console & Bing Webmaster first-party overlay

### Phase 3 — Hybrid Crawler & Desktop Sync
- `C-01` Coqui Desktop sync API & chunked snapshot ingestion
- `C-02` Historical crawl comparison & technical diff viewer
- `C-03` Headless `coqui-worker` Rust container & Redis job bridge
- `C-04` Live SERP Top-10 content brief & competitor page scraper

### Phase 4 — Backlink Intelligence & Link-Rot Monitor
- `B-01` Backlink profile overview & referring domains explorer
- `B-02` New & lost backlinks timeline
- `B-03` Active backlink watchlist & link-rot monitor
- `B-04` Competitor link intersect & broken backlink reclaim

### Phase 5 — Hosting Platform & Analytics Moat
- `H-01` Runnr hosting post-deploy SEO smoke tests
- `H-02` Server access log bot & AI crawler telemetry
- `H-03` GA4 & Plausible organic traffic attribution
- `H-04` Subscription billing, plan tiers & usage metering

## Updating the Backlog

Edit `tools/plan/epics.mjs` or `tools/plan/features.mjs`, regenerate artifacts with `npm run plan:build`, and sync GitHub issues and Project #17 idempotently with `tools/github/publish.ps1`.
