# Coqui SEO Platform (`coquiseo.com`)

**Cloud SEO, SERP intelligence, backlink monitoring, and hybrid crawl platform built with Laravel 13 — the cloud companion to [Coqui](https://github.com/adamgreenwell/coqui) and native SEO layer for Runnr hosting.**

## Architecture

Coqui SEO splits the web into two layers so you get Ahrefs/Moz-class intelligence without running a petabyte-scale global crawl cluster:

```text
┌───────────────────────────┐        Sanctum API         ┌────────────────────────────────┐
│   Coqui Desktop (Tauri)   │ ───── /api/v1/crawls/* ──▶ │  coqui-platform (Laravel 13)   │
│  Local Rust SQLite crawl  │                            │  app.coquiseo.com              │
└───────────────────────────┘                            │  Postgres 17 · Redis · Horizon │
                                                         │  Reverb · Shared DataForSEO    │
┌───────────────────────────┐     Deploy / Bot Logs      └───────┬────────────────┬───────┘
│   Runnr Hosting Platform  │ ───────────────────────────────────┘                │
└───────────────────────────┘                                                     │
                                                    ┌─────────────────────────────┴──────────────────────────┐
                                                    ▼                                                        ▼
                                     ┌─────────────────────────────┐                          ┌─────────────────────────────┐
                                     │      DataForSEO v3 API      │                          │    coqui-worker (Rust)      │
                                     │ Standard Postbacks + Shared │                          │  Targeted scheduled crawls, │
                                     │  Cache + Hard Spend Guard   │                          │  top-10 briefs & link rot   │
                                     └─────────────────────────────┘                          └─────────────────────────────┘
```

## Stack

- **Backend:** Laravel 13 (PHP 8.4), Horizon (Redis queues), Reverb (WebSockets), Sanctum (Coqui Desktop tokens), Fortify, Pennant, Laravel MCP (`laravel/mcp`), Laravel Boost
- **SEO Data Providers:** DataForSEO v3 (SERP rank tracking, Backlinks API, Labs fallback) + SE Ranking Data API (flat-batch keywords & domain visibility)
- **Frontend:** Inertia v3, React 19, TypeScript, Tailwind CSS v4
- **Database & Cache:** PostgreSQL 17, Redis 7
- **Cloud Crawler:** Headless Rust `coqui-worker` sidecar sharing `coqui-core`
- **Deployment Target:** Bare-metal server running Coolify via `docker-compose.yml`

## Documentation & Roadmap

- [Wiki home (`docs/wiki/`)](docs/wiki/Home.md) & [GitHub Wiki sync guide](docs/github-wiki.md)
- [Development plan & 5-phase roadmap](docs/development-plan.md)
- [Desktop ↔ Cloud sync contract](docs/desktop-sync-contract.md)
- [Roadmap matrix CSV](docs/reference/roadmap-matrix.csv)
- [Backlog issues](https://github.com/adamgreenwell/coqui-platform/issues) and [Project board](https://github.com/users/adamgreenwell/projects/17/views/1?layout_template=board)

## Local & Coolify Setup

1. Copy `.env.example` to `.env` and fill `APP_KEY`, `DB_*`, and `DATAFORSEO_*` credentials (`DATAFORSEO_API_BASE_URL` defaults to `https://sandbox.dataforseo.com` and `DATAFORSEO_SCHEDULED_SPEND` defaults to `false`).
2. Start the stack:

```bash
docker compose up -d --build
```

3. Regenerate roadmap docs and GitHub issue payloads after editing `tools/plan/*.mjs`:

```bash
npm run plan:build
```
