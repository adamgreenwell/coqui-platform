# SE Ranking and Multi-Provider Routing

Coqui SEO Platform pairs **DataForSEO v3** with the **SE Ranking Data API** (`api.seranking.com`) so standalone API credits on both platforms work together without requiring a seat-based SE Ranking SaaS subscription.

## Capability Split

| Capability | Primary Provider | Fallback / Notes |
| --- | --- | --- |
| **Backlinks & Referring Domains** | **DataForSEO** (`backlinks/*`) | SE Ranking's standalone API does not provide backlink endpoints without a regular subscription plan |
| **SERP Rank Tracking & AI Overviews** | **DataForSEO** (`serp/google/organic/task_post`) | Standard queue + postbacks; lowest cost per SERP with device & city/ZIP precision |
| **Bulk Keyword Screening** | **SE Ranking** (`/v1/keywords/export`) | Flat 100 credits per request (~1,000 keywords/call) for accepted ASCII queries |
| **Non-ASCII, Numeric & Overflow Keywords** | **DataForSEO Labs** (`keyword_overview/live`) | Automatically catches queries rejected by SE Ranking's `us` database (`™`, accents, bare years) |
| **Domain Organic Visibility** | **SE Ranking** (`/v1/domain/overview/db`) + **DataForSEO Labs** | Archived append-only in `seranking_archive` because SE Ranking ignores historical date parameters |

## Hard-Won SE Ranking Protections

1. **Acceptance Partitioning (`SeRankingAcceptanceRule`):** SE Ranking's `us` keyword database rejects an entire batch if even one keyword contains non-ASCII characters, lacks alphabetic letters (e.g. `2026`), or carries search operators (`site:`, `"`, `+`, `%`). `SeoProviderRouter` partitions those queries out before sending and routes them to DataForSEO.
2. **Payload-Size Bisection (`SeRankingClient`):** Rather than trusting a fixed keyword count, batches returning HTTP `400`, `413`, `414`, or `422` (the ~26 KB JSON payload ceiling) are automatically halved down to 50 keywords and retried.
3. **429 Backoff:** Rate-limited `429` responses are not billed; `SeRankingClient` honors `Retry-After` up to 5 attempts.
4. **Credit Guardrails (`SeRankingSpendGuard`):** Enforces `SERANKING_SCHEDULED_SPEND=false` by default plus `daily_credit_cap` and `monthly_credit_cap` tracked in `seranking_spend_ledger`.
5. **Append-Only Archive (`seranking_archive`):** Point-in-time domain snapshots are hash-deduplicated and preserved chronologically because SE Ranking cannot replay past months retrospectively.
