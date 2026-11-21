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
- **Deployment Target:** Bare-metal server running Coolify via `docker-compose.coolify.yml` (`docker-compose.yml` is for local development)

## Documentation & Roadmap

- [Wiki home (`docs/wiki/`)](docs/wiki/Home.md) & [GitHub Wiki sync guide](docs/github-wiki.md)
- [Development plan & 5-phase roadmap](docs/development-plan.md)
- [Desktop ↔ Cloud sync contract](docs/desktop-sync-contract.md)
- [Roadmap matrix CSV](docs/reference/roadmap-matrix.csv)
- [Backlog issues](https://github.com/adamgreenwell/coqui-platform/issues) and [Project board](https://github.com/users/adamgreenwell/projects/17/views/1?layout_template=board)

## Local Setup

For production on Coolify, follow [Coolify Deployment and Operations](docs/wiki/Coolify-Deployment-And-Operations.md).

1. Copy `.env.example` to `.env` and fill `APP_KEY`, `DB_*`, and `DATAFORSEO_*` credentials (`DATAFORSEO_API_BASE_URL` defaults to `https://sandbox.dataforseo.com` and `DATAFORSEO_SCHEDULED_SPEND` defaults to `false`).
2. Start the stack:

```bash
docker compose up -d --build
```

   To start with a usable login and a populated project, run the migrations and seed the demo data:

```bash
docker compose exec app php artisan migrate --seed
```

   This creates the login from `DEMO_USER_EMAIL` / `DEMO_USER_PASSWORD` in `.env` (see `.env.example`), a Pro workspace and a "Demo Roastery" project with two synced crawls, 30 days of rank history, 90 days of backlink history, a link watchlist and a week of bot telemetry. No DataForSEO or other provider credentials are needed to browse it. The seeder refuses to run when `APP_ENV=production`, and running it again leaves existing demo data alone (use `php artisan migrate:fresh --seed` to rebuild).

3. Regenerate roadmap docs and GitHub issue payloads after editing `tools/plan/*.mjs`:

```bash
npm run plan:build
```
