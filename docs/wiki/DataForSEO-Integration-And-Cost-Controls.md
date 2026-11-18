# DataForSEO Integration and Cost Controls

DataForSEO is the wholesale data engine behind Coqui SEO's global SERP, keyword, and backlink features. Because every API call carries variable COGS, cost control is treated as core infrastructure in `App\Domains\Serp\DataForSeo` and `config/coqui.php`.

## Safety Defaults

| Setting | Non-Production Default | Production Intent |
| --- | --- | --- |
| `DATAFORSEO_API_BASE_URL` | `https://sandbox.dataforseo.com` | `https://api.dataforseo.com` |
| `DATAFORSEO_SCHEDULED_SPEND` | `false` | `true` only after budgets and backups are verified |
| `DATAFORSEO_DAILY_CAP_USD` | `5.00` | Tuned per traffic/revenue |
| `DATAFORSEO_MONTHLY_CAP_USD` | `100.00` | Global circuit breaker |

## Request & Ledger Pipeline

1. **Global Cross-Project Cache (`DataForSeoCacheRepository`):**
   - Requests normalize endpoint, keywords/domain, `location_code`, and `language_code` into a deterministic SHA-256 `cache_key` in `dataforseo_cache`.
   - Default TTLs in `config/coqui.php`: `keyword_overview` (30 days), `domain_overview` (14 days), `backlinks_summary` (14 days), `serp_snapshot` (7 days).
   - If User A and User B query the same keyword in the same market within TTL, the second lookup costs \$0.
2. **Pre-Flight Spend Guard (`DataForSeoSpendGuard`):**
   - Checks `DATAFORSEO_SCHEDULED_SPEND` for background batches.
   - Estimates request cost from `config/coqui.php['dataforseo']['prices']`.
   - Enforces global daily cap, global monthly cap, and the workspace's `monthly_dfs_budget_usd` before issuing an HTTP request.
3. **No Automatic Retries on Paid POSTs (`DataForSeoClient`):**
   - Free read endpoints (`appendix/user_data`, `tasks_ready`, `task_get`) retry on transient network drops.
   - Paid `POST` endpoints (`task_post`, `live`) never retry automatically on timeouts so an in-flight DataForSEO task is never billed twice.
4. **Actual Spend Ledger (`dataforseo_spend_ledger`):**
   - Every paid response records both the pre-flight estimate and the exact vendor-reported `cost` field returned by DataForSEO.

## Standard Queue + Postbacks

Automated rank tracking submits tasks to DataForSEO's **Standard queue** with a signed `postback_url` pointing to `POST /api/webhooks/dataforseo`. Results are verified against the per-task `postback_token` stored in `dataforseo_tasks`, and any missed webhooks are recovered every 10 minutes by `php artisan dataforseo:collect-tasks`.
