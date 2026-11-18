# Platform Architecture

Coqui SEO Platform is structured as a modular Laravel 13 monolith running PHP 8.4, backed by PostgreSQL 17, Redis 7, Laravel Horizon, Laravel Reverb, and a Rust `coqui-worker` sidecar.

## Domain Map (`app/Domains/`)

| Domain | Responsibilities | Key Classes & Models |
| --- | --- | --- |
| `Projects` | Multi-tenant workspaces, member roles, project canonicalization, competitor registry | `Workspace`, `Project`, `ProjectCompetitor`, `ProjectPolicy` |
| `Serp` | DataForSEO HTTP transport, shared cache, spend guard, rank tracking, keyword explorer | `DataForSeoClient`, `DataForSeoCacheRepository`, `DataForSeoSpendGuard`, `TrackedKeyword`, `KeywordRankSnapshot` |
| `Crawls` | Desktop snapshot ingestion (`coqui-core` camelCase DTOs), crawl history, `coqui-worker` bridge | `CrawlSyncPayload`, `IngestDesktopCrawlSnapshot`, `CoquiWorkerClient`, `CrawlRun`, `CrawlUrl`, `CrawlIssue` |
| `Backlinks` | DataForSEO backlink discovery and targeted `coqui-worker` link-rot monitoring | `MonitoredBacklink`, `BacklinkVerificationService` |
| `Integrations` | GSC, Bing, GA4, Plausible OAuth integrations, Runnr deploy webhooks, bot log telemetry | `ProjectIntegration`, `RunnrDeployWebhookHandler` |

## Authentication & Tenancy

- **Web App:** Laravel Fortify session auth + Inertia v3 React 19 UI.
- **Desktop & CLI Clients:** Laravel Sanctum personal access tokens prefixed with `coqui_`, scoped via token abilities (e.g. `crawls:sync`).
- **Authorization:** Every project action checks `ProjectPolicy` to verify the authenticated user owns or belongs to the owning `Workspace`.
- **Realtime:** Private broadcast channel `project.{projectId}` (`routes/channels.php`) streams progress events over Laravel Reverb.

## Database Overview (PostgreSQL 17)

1. `users`, `workspaces`, `workspace_memberships`, `personal_access_tokens`
2. `projects`, `project_competitors`
3. `dataforseo_cache`, `dataforseo_spend_ledger`, `dataforseo_tasks`
4. `tracked_keywords`, `keyword_rank_snapshots`
5. `crawl_runs`, `crawl_urls`, `crawl_links`, `crawl_issues`
6. `monitored_backlinks`
7. `project_integrations`, `bot_crawl_logs`
