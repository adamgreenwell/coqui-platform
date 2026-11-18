# Coolify Deployment and Operations

The MVP runs on a personal bare-metal server managed by **Coolify** using the root `docker-compose.yml` and multi-stage `Dockerfile`.

## Compose Services

| Service | Role | Port / Exposure |
| --- | --- | --- |
| `nginx` | Reverse proxy + static Vite assets + WebSocket upgrade for `/app` | Exposed (`${APP_HTTP_PORT:-8080}:80`) for Coolify proxy |
| `app` | PHP 8.4-FPM running Laravel 13, migrations, config/route caching | Internal `:9000` |
| `horizon` | `php artisan horizon` processing `default`, `serp`, `crawls`, `backlinks`, `telemetry` | Internal |
| `reverb` | `php artisan reverb:start` WebSocket server | Internal `:8080` (proxied via `/app`) |
| `scheduler` | `php artisan schedule:work` running scheduled DataForSEO & backlink commands | Internal |
| `postgres` | PostgreSQL 17 with persistent `postgres_data` volume | Internal `:5432` |
| `redis` | Redis 7 AOF persistence for queues, cache, sessions, Horizon | Internal `:6379` |
| `coqui-worker` | Optional (`worker` profile) headless Rust sidecar image | Internal `:8085` |

## Required Coolify Environment Variables

1. **Core:** `APP_NAME="Coqui SEO"`, `APP_ENV=production`, `APP_KEY` (generated), `APP_DEBUG=false`, `APP_URL=https://app.coquiseo.com`
2. **Database & Redis:** `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_HOST=redis`
3. **Reverb:** `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `VITE_REVERB_HOST=app.coquiseo.com`, `VITE_REVERB_PORT=443`, `VITE_REVERB_SCHEME=https`
4. **DataForSEO:** `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD`, `DATAFORSEO_API_BASE_URL` (use `https://sandbox.dataforseo.com` until live billing is desired), `DATAFORSEO_SCHEDULED_SPEND` (`false` until verified), `DATAFORSEO_DAILY_CAP_USD`, `DATAFORSEO_MONTHLY_CAP_USD`
5. **Integrations:** `COQUI_WORKER_URL`, `COQUI_WORKER_TOKEN`, `RUNNR_WEBHOOK_SECRET`

## Scheduled Commands (`bootstrap/app.php`)

- `dataforseo:collect-tasks` — Every 10 minutes (free; recovers missed Standard postbacks).
- `dataforseo:run-rank-batches` — Daily at `05:10 UTC` (guarded by `DATAFORSEO_SCHEDULED_SPEND`).
- `coqui:verify-backlinks` — Weekly Monday at `03:30 UTC` via `coqui-worker`.
