# Coolify Deployment and Operations

Production runs on a self-hosted **Coolify** server using `docker-compose.coolify.yml` (Docker Compose build pack) and the multi-stage `Dockerfile`. The root `docker-compose.yml` is for local development only.

## Compose Services (`docker-compose.coolify.yml`)

| Service | Role | Exposure |
| --- | --- | --- |
| `app` | nginx: serves static/Vite assets, passes PHP to `php`, proxies WebSockets on `/app` to `reverb` | Container port `80`; **assign the domain here** |
| `php` | PHP 8.4-FPM running Laravel 13. Runs migrations and config/route/view caching on start | Internal `:9000` (FastCGI, not HTTP) |
| `horizon` | `php artisan horizon` processing `default`, `serp`, `crawls`, `backlinks`, `telemetry` | Internal |
| `reverb` | `php artisan reverb:start` WebSocket server | Internal `:8080` (via `app` on `/app`) |
| `scheduler` | `php artisan schedule:work` | Internal |
| `postgres` | PostgreSQL 17, persistent `postgres_data` volume | Internal `:5432` |
| `redis` | Redis 7 with AOF, used for queues, cache, sessions and Horizon | Internal `:6379` |

`coqui-worker` (the Rust crawl sidecar) is not part of the production stack until its image exists (#58). Cloud crawls and backlink link-rot checks that need it will not run.

### Start-up order

`postgres`/`redis` healthy → `php` migrates and caches config (becomes healthy only if the migration succeeds) → `app` (nginx), `horizon` and `scheduler` start. A failed migration keeps `php` unhealthy and the deploy fails instead of shipping a half-migrated app.

## Creating the Resource in Coolify

1. **New resource → Docker Compose** (from the GitHub repo, branch `main`), with compose file location `/docker-compose.coolify.yml`.
2. **Domain:** set `https://<app domain>` on the **`app`** service only (it is nginx on port 80; `php` speaks FastCGI and would return 502). Coolify's proxy issues the certificate and terminates TLS. Laravel trusts the forwarded headers (`bootstrap/app.php`), so URLs and redirects are generated as `https://`.
3. **Environment variables:** Coolify lists every `${VAR}` from the compose file. Fill in the checklist below. Mark **`REVERB_APP_KEY`** and **`VITE_REVERB_HOST`** (and any `VITE_*` you change) as available at build time, because Vite bakes them into the browser bundle.
4. **Deploy.** Then check:
   - `https://<app domain>/up` returns 200.
   - The login page loads its CSS/JS over `https` (no mixed-content warnings).
   - Live updates connect: the browser console shows the WebSocket to `wss://<app domain>/app/...` open.
5. **First account:** register through the UI. Then, from the `php` container's terminal:
   - Grant yourself operator access, which is needed for the Horizon dashboard at `/horizon`: `php artisan coqui:operator <your email>`. Use `--revoke` to remove it.
   - If needed, set the workspace plan: `php artisan billing:set-tier <workspace id or slug> <free|pro|agency>`. Add `--runnr` for the Runnr add-on.

## Environment Variable Checklist

### Required (deploy is blocked while empty)

| Variable | Value |
| --- | --- |
| `APP_KEY` | `base64:` + 32 random bytes. Generate with `php artisan key:generate --show` (or `openssl rand -base64 32` and add the `base64:` prefix). Never change it after launch. |
| `APP_URL` | `https://<app domain>` |
| `DB_PASSWORD` | Long random password (also used to initialise Postgres on first boot) |
| `REVERB_APP_KEY` | Random string (build time too) |
| `REVERB_APP_SECRET` | Random string |
| `VITE_REVERB_HOST` | `<app domain>` with no scheme (build time) |

### Defaults you may want to change

| Variable | Default | Notes |
| --- | --- | --- |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | |
| `LOG_CHANNEL` / `LOG_LEVEL` | `stderr` / `info` | Logs appear in Coolify's log viewer |
| `MAIL_*` | `log` mailer | Set an SMTP provider for password resets and invites |
| `DATAFORSEO_API_BASE_URL` | `https://sandbox.dataforseo.com` | Switch to `https://api.dataforseo.com` for live data |
| `DATAFORSEO_SCHEDULED_SPEND` / `SERANKING_SCHEDULED_SPEND` | `false` | Turn on only after verifying budgets |
| `DATAFORSEO_DAILY_CAP_USD` / `DATAFORSEO_MONTHLY_CAP_USD` | `5.00` / `100.00` | Platform-wide spend caps |
| `COQUI_MCP_REQUIRE_AUTH` / `COQUI_MCP_RATE_LIMIT` | `true` / `60` | MCP tokens need the `mcp:use` ability |

### Integrations (optional; the feature stays off until set)

| Variables | Feature |
| --- | --- |
| `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD`, `DATAFORSEO_WEBHOOK_SECRET` | Keyword, SERP and backlink data |
| `SERANKING_API_KEY` | SE Ranking keyword/domain data |
| `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | Billing. Webhooks return 503 until the secret is set. Point the Stripe webhook at `https://<app domain>/api/webhooks/stripe`. |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, (`GOOGLE_REDIRECT_URI`) | Search Console / GA4. Register `https://<app domain>/integrations/google/callback` as an authorised redirect URI. |
| `BING_WEBMASTER_API_KEY` | Bing Webmaster data |
| `RUNNR_WEBHOOK_SECRET`, `RUNNR_TELEMETRY_TOKEN` | Runnr deploy webhooks and bot telemetry |
| `COQUI_WORKER_URL`, `COQUI_WORKER_TOKEN` | Cloud crawl worker (after #58) |

## Operations

- **Logs:** Coolify → resource → service → Logs (Laravel writes to stderr).
- **Artisan:** Coolify → `php` service → Terminal, for example `php artisan migrate:status` or `php artisan horizon:status`.
- **Backups:** enable Coolify's scheduled backups for the `postgres` service, or back up the `postgres_data` volume. `storage_data` holds uploaded/generated files.
- **Redeploys:** the `public_assets` volume is refreshed from the new image on every `php` start, so stale assets are not served.

## Scheduled Commands (`routes/console.php`)

- `dataforseo:collect-tasks`: every 10 minutes (free; recovers missed Standard postbacks).
- `dataforseo:run-rank-batches`: daily at `05:10 UTC` (guarded by `DATAFORSEO_SCHEDULED_SPEND`).
- `coqui:verify-backlinks`: weekly on Monday at `03:30 UTC` via `coqui-worker`.
