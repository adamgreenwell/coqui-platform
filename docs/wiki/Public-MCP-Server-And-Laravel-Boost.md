# Public MCP Server and Laravel Boost

Coqui SEO Platform includes **Laravel Boost** (`laravel/boost`) for agent development guidelines/tooling and exposes a public **Model Context Protocol (MCP)** server powered by **`laravel/mcp`**.

## Public MCP Endpoint (`routes/ai.php`)

- **HTTP / JSON-RPC Transport:** `POST /mcp/coqui` (registered via `Mcp::web()` in `routes/ai.php`, outside browser CSRF middleware).
- **Local Stdio Transport:** `php artisan mcp:start coqui-seo` (registered via `Mcp::local('coqui-seo', CoquiSeoServer::class)`).
- **Authentication Toggle:** Controlled via `COQUI_MCP_REQUIRE_AUTH` (`config('coqui.mcp.require_auth')`). Defaults to `false` for open discovery during MVP setup and switches on `auth:sanctum` gating when set to `true`.

## Exposed Tools (`App\Mcp\Servers\CoquiSeoServer`)

| Tool | Purpose |
| --- | --- |
| `inspect_project_seo` | Looks up a normalized project domain and returns its canonical URL, competitors, latest crawl run summary, tracked keyword count, and monitored backlink count |
| `lookup_keyword_metrics` | Runs `SeRankingAcceptanceRule` dry-run routing or live `SeoProviderRouter` lookups across SE Ranking and DataForSEO |
| `list_crawl_issues` | Lists technical SEO issues (`error`, `warning`, `opportunity`) from a domain's most recent crawl snapshot |

## Laravel Boost Configuration

`boost.json` in the repository root tracks enabled AI agent integrations (`codex`, `claude_code`), guidelines, and MCP settings so coding agents working in `coqui-platform` share consistent Laravel 13 conventions.
