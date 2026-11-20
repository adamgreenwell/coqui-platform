# Coqui SEO Platform Wiki

**Coqui SEO Platform** (`coquiseo.com` / `app.coquiseo.com`) is the Laravel 13 cloud SEO, SERP intelligence, backlink monitoring, and hybrid crawl companion to the [Coqui](https://github.com/adamgreenwell/coqui) Rust/Tauri desktop crawler and the Runnr hosting platform.

## Start Here

| If you want to know | Read |
| --- | --- |
| Why this platform exists and the build-vs-buy thesis | [Product Direction](Product-Direction.md) |
| How the Laravel 13 modular monolith and schema are structured | [Platform Architecture](Platform-Architecture.md) |
| How DataForSEO v3 costs are capped, cached, and audited | [DataForSEO Integration and Cost Controls](DataForSEO-Integration-And-Cost-Controls.md) |
| What is in each delivery phase and on Project #17 | [Development Roadmap](Development-Roadmap.md) |

## All Pages

- [Product Direction](Product-Direction.md)
- [Platform Architecture](Platform-Architecture.md)
- [DataForSEO Integration and Cost Controls](DataForSEO-Integration-And-Cost-Controls.md)
- [SE Ranking and Multi-Provider Routing](SE-Ranking-And-Multi-Provider-Routing.md)
- [Public MCP Server and Laravel Boost](Public-MCP-Server-And-Laravel-Boost.md)
- [Hybrid Crawler and Desktop Sync](Hybrid-Crawler-And-Desktop-Sync.md)
- [Rank Tracking and Search Overlays](Rank-Tracking-And-Search-Overlays.md)
- [Backlink Intelligence and Link Rot](Backlink-Intelligence-And-Link-Rot.md)
- [Runnr Hosting and Analytics Moat](Runnr-Hosting-And-Analytics-Moat.md)
- [Coolify Deployment and Operations](Coolify-Deployment-And-Operations.md)
- [Stripe Billing Setup](Stripe-Billing-Setup.md)
- [Development Roadmap](Development-Roadmap.md)

## Platform Guardrails

- **Never crawl the whole internet yourself:** Use DataForSEO v3 for global SERPs, search volumes, and backlink discovery; reserve custom crawling for user-owned sites, watchlist backlinks, and top-10 SERP content briefs.
- **Spend safety first:** `DATAFORSEO_API_BASE_URL` defaults to `https://sandbox.dataforseo.com` outside production, and `DATAFORSEO_SCHEDULED_SPEND` defaults to `false`.
- **Single source of crawl truth:** `coqui-core` owns crawl/extraction/issue semantics across Coqui Desktop and `coqui-worker`. Never duplicate SEO audit rules in PHP or JavaScript.
- **Zero secret persistence:** Strip `authPassword` and sensitive auth headers (`Authorization`, `Cookie`, `Proxy-Authorization`, `X-Api-Key`) before storing synced crawl configs.
