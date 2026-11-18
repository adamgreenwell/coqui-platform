# Product Direction

## Problem Statement

Modern SEO workflows are split across disconnected tools:

1. **Desktop crawlers** (like Screaming Frog and **Coqui Desktop**) deliver deep technical audits at zero marginal server cost using local CPU/RAM, but lack historical cloud diffing, team collaboration, and ongoing rank tracking.
2. **All-in-one SaaS platforms** (Ahrefs, Semrush, Moz) charge \$100–\$500+/month because they maintain massive distributed crawl clusters and petabyte-scale link graphs.
3. **Web hosting platforms** control the origin server, edge logs, and deployment pipeline—yet provide almost no SEO visibility.

## Strategy: The Coqui + DataForSEO + Runnr Loop

Coqui SEO (`coquiseo.com`) connects three complementary layers into one cohesive platform:

| Surface | Owner | Role |
| --- | --- | --- |
| **Coqui Desktop** (`adamgreenwell/coqui`) | Rust + Tauri 2 + Next.js | Unlimited local technical crawling, rendering, and exports on user hardware, with one-click cloud sync |
| **Coqui SEO Platform** (`coquiseo.com`) | Laravel 13 + DataForSEO + `coqui-worker` | Shared project workspace, daily/weekly rank tracking, keyword research, backlink intelligence, and scheduled cloud crawls |
| **Runnr Hosting** | Hosting Control Plane | Zero-config post-deploy SEO smoke tests and real-time server access-log telemetry for Googlebot, Bingbot, and AI crawlers |

## Why Build on Wholesale SEO APIs + a Targeted Rust Crawler

Attempting to build a web-scale backlink and SERP index on modest hardware is capital-prohibitive. Instead:

- **DataForSEO v3** converts fixed crawling/proxy capital expenditure into variable per-call COGS, buffered by cross-project caching and Standard-queue batching.
- **`coqui-worker`** (compiled directly from `coqui-core` / `coqui-crawl`) handles the high-precision micro-web gaps that wholesale APIs either miss or charge too much for: weekly technical checks on owned sites, active backlink link-rot verification, post-deploy smoke checks, and live top-10 SERP competitor content extraction.
- **First-party APIs** (Google Search Console, Bing Webmaster Tools, GA4, Plausible) add real click and traffic attribution at zero data-vendor cost.
