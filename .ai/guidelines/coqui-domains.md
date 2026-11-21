## Coqui domain layout

Coqui is a Laravel 13 / PHP 8.4 app with an Inertia React (TypeScript) front end. Business logic lives in `app/Domains/<Domain>`. Controllers in `app/Http/Controllers` should stay thin: they authorize, validate, call a domain service or action, and render.

| Domain | Owns |
| --- | --- |
| `Projects` | Workspaces, memberships and roles, projects, competitors, `ProjectActivity` (the activity feed; log through `ProjectActivity::log()`). |
| `Billing` | Plans, `PlanFeature` (Pennant features, checked with `$workspace->hasFeature()` / `ensureFeature()` or the `feature:` route middleware), Stripe/Cashier. |
| `Serp` | Rank tracking, keyword research and gap, content briefs, the SEO data providers and their spend guards (see the provider routing guideline). |
| `Crawls` | Desktop-synced and cloud (coqui-worker) crawls, crawl diffs and share links. |
| `Backlinks` | Backlink profile, history timeline, alerts and digest, link intersect, the monitored-link watchlist. |
| `Integrations` | Search Console / Bing / GA4 / Plausible / Umami sync, Runnr hosting webhooks and bot telemetry. |

Conventions:
- Models that appear in URLs use `HasPublicUuid`: the integer `id` stays internal and routes bind on `uuid`. Never expose sequential IDs to clients. Before comparing request input with a `uuid` column, check it with `Str::isUuid()`, because Postgres rejects non-UUID input instead of matching nothing.
- Authorization goes through `ProjectPolicy`:
  - `view` for any workspace member;
  - `update` for owner, admin and editor;
  - `spend` for anyone allowed to trigger paid provider calls. Read-only members get cached data only.
- Events that the UI listens to over Reverb use `ShouldBroadcastNow`. Wrap a dispatch in `rescue()` when a broadcast failure must not fail the request.
- Tests run on SQLite locally (phpunit.xml) and on Postgres 17 in CI. Write SQL and test data that work on both: no SQLite-only functions, valid UUIDs in uuid columns, integers in integer columns.
- User-facing copy (UI text, emails, Slack messages) must never name third-party data providers. Internal docs and logs may.
