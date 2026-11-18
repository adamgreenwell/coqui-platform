import fs from 'node:fs';
import path from 'node:path';
import { epics } from './epics.mjs';
import { features } from './features.mjs';

const ROOT = path.resolve(import.meta.dirname, '../..');
const DOCS = path.join(ROOT, 'docs');
const REF = path.join(DOCS, 'reference');
const GEN = path.join(ROOT, 'tools/plan/.generated');

fs.mkdirSync(REF, { recursive: true });
fs.mkdirSync(GEN, { recursive: true });

const epicById = new Map(epics.map((e) => [e.id, e]));

const phaseNames = {
  1: 'Phase 1 - Foundation & DataForSEO Core',
  2: 'Phase 2 - Rank Tracking & Keyword Intelligence',
  3: 'Phase 3 - Hybrid Crawler & Desktop Sync',
  4: 'Phase 4 - Backlink Intelligence & Link-Rot Monitor',
  5: 'Phase 5 - Hosting Platform & Analytics Moat',
};

// ---------- roadmap matrix CSV ----------

const esc = (v) => `"${String(v).replace(/"/g, '""')}"`;
const matrixRows = features.map((f) => ({
  id: f.id,
  epic: epicById.get(f.epic).title,
  title: f.title,
  area: f.area,
  phase: f.phase,
  size: f.size,
  priority: f.priority,
  status: 'Todo',
  depends: f.deps.join(' '),
}));

fs.writeFileSync(
  path.join(REF, 'roadmap-matrix.csv'),
  'id,epic,title,area,phase,size,priority,status,depends\n' +
    matrixRows
      .map((r) =>
        [r.id, r.epic, r.title, r.area, r.phase, r.size, r.priority, r.status, r.depends]
          .map(esc)
          .join(','),
      )
      .join('\n') +
    '\n',
);

// ---------- GitHub issues payload ----------

function featureBody(f) {
  const epic = epicById.get(f.epic);
  return [
    '## Summary',
    f.summary,
    '',
    `**Epic:** ${f.epic} - ${epic.title}`,
    `**Phase:** ${f.phase} | **Size:** ${f.size} | **Priority:** ${f.priority} | **Area:** ${f.area}`,
    '',
    '## Scope',
    ...f.scope.map((s) => `- ${s}`),
    '',
    '## Acceptance criteria',
    ...f.acceptance.map((s) => `- [ ] ${s}`),
    '',
    '## Dependencies',
    f.deps.length ? f.deps.map((d) => `- ${d}`).join('\n') : '- None',
    '',
    `<!--EPIC:${f.epic}-->`,
  ].join('\n');
}

function epicBody(e, childKeys) {
  return [
    '## Outcome',
    e.outcome,
    '',
    '## Summary',
    e.summary,
    '',
    `**Phase:** ${e.phase} | **Size:** ${e.size} | **Priority:** ${e.priority}`,
    '',
    '## Features in this epic',
    ...childKeys.map((k) => `- [ ] ${k}`),
    '',
    '## Definition of done',
    '- All child features closed with acceptance criteria met',
    '- Automated tests covering domain logic and API contracts passing in CI',
    '- Verified on the bare-metal Coolify staging stack',
  ].join('\n');
}

const epicIssues = epics.map((e) => ({
  key: e.id,
  type: 'epic',
  title: `EPIC ${e.id}: ${e.title}`,
  body: epicBody(
    e,
    features.filter((f) => f.epic === e.id).map((f) => `${f.id} - ${f.title}`),
  ),
  labels: ['type/epic', `epic/${e.slug}`, `phase/${e.phase}`, `size/${e.size}`, `priority/${e.priority}`],
  milestone: phaseNames[e.phase],
  phase: e.phase,
  size: e.size,
  priority: e.priority,
  epic: e.id,
}));

const featureIssues = features.map((f) => ({
  key: f.id,
  type: 'feature',
  title: `${f.id} ${f.title}`,
  body: featureBody(f),
  labels: [
    'type/feature',
    `epic/${epicById.get(f.epic).slug}`,
    `area/${f.area}`,
    `phase/${f.phase}`,
    `size/${f.size}`,
    `priority/${f.priority}`,
  ],
  milestone: phaseNames[f.phase],
  phase: f.phase,
  size: f.size,
  priority: f.priority,
  epic: f.epic,
}));

fs.writeFileSync(
  path.join(GEN, 'issues.json'),
  JSON.stringify({ epics: epicIssues, features: featureIssues, phaseNames }, null, 2),
);

// ---------- docs/development-plan.md ----------

const byPhase = [1, 2, 3, 4, 5].map((phase) => ({
  phase,
  name: phaseNames[phase],
  items: features.filter((f) => f.phase === phase),
}));

const devPlan = [
  '# Coqui SEO Platform — Architecture & Development Plan',
  '',
  '**Coqui SEO Platform** (`coquiseo.com` / `app.coquiseo.com`) is the cloud SEO, SERP intelligence, and hybrid crawl platform built with **Laravel 13** to complement the [Coqui](https://github.com/adamgreenwell/coqui) Rust/Tauri desktop crawler and integrate natively with the Runnr hosting platform.',
  '',
  '- **Backlog:** 6 epics and 24 feature issues tracked on the [Coqui SEO Platform project board](https://github.com/users/adamgreenwell/projects/17/views/1?layout_template=board).',
  '- **Stack:** Laravel 13 (PHP 8.4), Inertia v3 + React 19, PostgreSQL 17, Redis + Laravel Horizon, Laravel Reverb, Sanctum, and a headless Rust `coqui-worker` sidecar.',
  '- **MVP Infrastructure:** Bare-metal server running Coolify via Docker Compose, transitioning to horizontally scaled worker/DB nodes at public launch.',
  '',
  '---',
  '',
  '## 1. Strategic Build-vs-Buy Architecture',
  '',
  'Building a global web-scale link and SERP index from scratch requires petabytes of storage, distributed proxy fleets, and continuous worldwide crawling. Coqui SEO avoids that fixed infrastructure trap by splitting the web into two layers:',
  '',
  '| Layer | Powered By | Role | Economics |',
  '| --- | --- | --- | --- |',
  '| **Macro Web** | **DataForSEO v3 APIs** | Global SERPs, search volume, keyword difficulty, competitor gaps, domain backlink discovery | Variable COGS, aggressively cached globally & queued via Standard postbacks |',
  '| **Micro Web (Desktop)** | **Coqui Desktop** (Tauri + Rust) | Deep, zero-marginal-cost technical crawls run on the user\'s hardware, synced to cloud | $0 server crawl cost |',
  '| **Micro Web (Cloud)** | **`coqui-worker`** (`coqui-core`) | Scheduled light audits, live SERP top-10 scraping, watchlist backlink verification, deploy checks | Bounded single-binary Rust worker |',
  '| **First-Party & Edge** | **GSC, Bing, GA4, Plausible, Runnr** | Real clicks/impressions, traffic attribution, post-deploy smoke tests, server-log bot tracking | $0 API cost + unique hosting moat |',
  '',
  '---',
  '',
  '## 2. Coolify Service Topology (MVP Bare-Metal)',
  '',
  '```text',
  '                        ┌─────────────────────────┐',
  '                        │   coquiseo.com / app.   │',
  '                        │  Nginx + PHP 8.4-FPM    │',
  '                        └────────────┬────────────┘',
  '                                     │',
  '        ┌────────────────────────────┼────────────────────────────┐',
  '        ▼                            ▼                            ▼',
  '┌───────────────┐          ┌───────────────────┐        ┌───────────────────┐',
  '│ PostgreSQL 17 │          │ Redis 7 + Horizon │        │  Laravel Reverb   │',
  '│ (JSONB/Citus) │          │  + Scheduler      │        │   (WebSockets)    │',
  '└───────────────┘          └─────────┬─────────┘        └───────────────────┘',
  '                                     │',
  '                 ┌───────────────────┴───────────────────┐',
  '                 ▼                                       ▼',
  '     ┌───────────────────────┐               ┌───────────────────────┐',
  '     │   DataForSEO v3 API   │               │ coqui-worker (Rust)   │',
  '     │ Standard Postbacks +  │               │ Shared coqui-core     │',
  '     │  Shared Cache Layer   │               │ Targeted Cloud Audits │',
  '     └───────────────────────┘               └───────────────────────┘',
  '```',
  '',
  '---',
  '',
  '## 3. Laravel Domain Boundaries',
  '',
  '```text',
  'app/Domains/',
  '├── Projects/       # Workspaces, memberships, sites, competitors, Sanctum desktop tokens',
  '├── Serp/           # DataForSeoClient, SpendGuard, CacheRepository, RankTracker, Keywords',
  '├── Crawls/         # Desktop CrawlSyncPayload ingest, CrawlRun diffs, CoquiWorkerClient',
  '├── Backlinks/      # DataForSEO backlink summaries + active watchlist link-rot checks',
  '└── Integrations/   # GSC, Bing, GA4, Plausible, Runnr deploy webhooks, server bot telemetry',
  '```',
  '',
  '---',
  '',
  '## 4. DataForSEO Cost Controls & Unit Economics',
  '',
  '1. **Sandbox First:** `DATAFORSEO_API_BASE_URL` defaults to `https://sandbox.dataforseo.com` in non-production environments.',
  '2. **Scheduled Spend Kill-Switch:** Automated batch purchases require `DATAFORSEO_SCHEDULED_SPEND=true` explicitly.',
  '3. **Standard Queue + Postbacks:** Rank tracking uses DataForSEO Standard queues with `/api/webhooks/dataforseo` postbacks rather than expensive Live calls.',
  '4. **Shared Cross-Tenant Cache:** Keyword overview/volume (`30d` TTL) and domain summaries (`14d` TTL) are cached by normalized signature so duplicate lookups across users cost $0.',
  '5. **Spend Guard Ledger:** Every request estimates price beforehand, checks daily/monthly caps and workspace quotas, and reconciles exact vendor-reported `cost` fields.',
  '',
  '---',
  '',
  '## 5. Phased MVP Roadmap',
  '',
  ...byPhase.flatMap((group) => [
    `### ${group.name}`,
    '',
    '| ID | Feature | Epic | Priority | Size | Area |',
    '| --- | --- | --- | --- | --- | --- |',
    ...group.items.map(
      (f) => `| ${f.id} | ${f.title} | ${f.epic} | ${f.priority} | ${f.size} | ${f.area} |`,
    ),
    '',
  ]),
  'See [roadmap-matrix.csv](reference/roadmap-matrix.csv) for machine-readable dependencies and [desktop-sync-contract.md](desktop-sync-contract.md) for the exact `coqui-core` JSON sync schema.',
  '',
].join('\n');

fs.writeFileSync(path.join(DOCS, 'development-plan.md'), devPlan);

// ---------- docs/desktop-sync-contract.md ----------

const syncContract = [
  '# Coqui Desktop ↔ Cloud Sync Contract',
  '',
  'This contract defines the JSON payloads exchanged between the **Coqui** desktop application (`crates/coqui-core/src/lib.rs`) and **Coqui SEO Platform** (`api.coquiseo.com/api/v1/crawls/*`).',
  '',
  'Because `coqui-core` serializes every struct with `#[serde(rename_all = "camelCase")]`, all API requests use `camelCase` field names directly so the desktop app and `coqui-worker` can stream records without translation overhead.',
  '',
  '---',
  '',
  '## Authentication',
  '',
  '- **Scheme:** `Authorization: Bearer <sanctum_personal_access_token>`',
  '- **Required Token Ability:** `crawls:sync`',
  '- **Scoping:** Every sync route resolves the target `Project` and authorizes workspace membership (`update` policy).',
  '',
  '---',
  '',
  '## Lifecycle Endpoints',
  '',
  '### 1. Initialize or Upsert Crawl Run',
  '`POST /api/v1/projects/{project}/crawls/sync`',
  '',
  'Maps 1:1 to `coqui_core::CrawlSummary` + `coqui_core::CrawlConfig`:',
  '',
  '```json',
  '{',
  '  "id": "550e8400-e29b-41d4-a716-446655440000",',
  '  "seed": "https://example.com/",',
  '  "seedKind": "url",',
  '  "status": "completed",',
  '  "config": {',
  '    "maxDepth": 3,',
  '    "maxUrls": 5000,',
  '    "concurrency": 8,',
  '    "requestTimeoutMs": 15000,',
  '    "userAgent": "Coqui/0.1.0 (+https://github.com/adamgreenwell/coqui)",',
  '    "sameHostOnly": true,',
  '    "respectRobots": true,',
  '    "includePatterns": [],',
  '    "excludePatterns": [],',
  '    "authUser": "optional-user",',
  '    "customHeaders": []',
  '  },',
  '  "createdAt": 1795022400000,',
  '  "startedAt": 1795022401000,',
  '  "finishedAt": 1795022460000,',
  '  "totalUrls": 412,',
  '  "source": "desktop"',
  '}',
  '```',
  '',
  '> **Security Rule:** Even if a client sends `authPassword` or `Authorization` / `Cookie` values inside `config`, Laravel explicitly strips secrets before persisting `crawl_runs.config`.',
  '',
  '### 2. Stream Chunked Records',
  '`POST /api/v1/projects/{project}/crawls/{crawlId}/chunks`',
  '',
  'Accepts up to 1,000 `urls`, 2,500 `links`, and 2,500 `issues` per request, using upsert semantics on `(crawl_run_id, url)` so interrupted uploads can retry safely.',
  '',
  '```json',
  '{',
  '  "urls": [',
  '    {',
  '      "id": 1,',
  '      "crawlId": "550e8400-e29b-41d4-a716-446655440000",',
  '      "url": "https://example.com/",',
  '      "status": 200,',
  '      "contentType": "text/html; charset=utf-8",',
  '      "title": "Example Home",',
  '      "metaDescription": "Example description",',
  '      "h1": "Welcome",',
  '      "h1Count": 1,',
  '      "canonical": "https://example.com/",',
  '      "metaRobots": "index, follow",',
  '      "redirectTarget": null,',
  '      "redirectChain": [],',
  '      "wordCount": 642,',
  '      "depth": 0,',
  '      "responseTimeMs": 145,',
  '      "sizeBytes": 28412,',
  '      "error": null,',
  '      "discoveredAt": 1795022401200',
  '    }',
  '  ],',
  '  "links": [',
  '    {',
  '      "fromUrl": "https://example.com/",',
  '      "toUrl": "https://example.com/pricing",',
  '      "anchor": "Pricing",',
  '      "rel": null,',
  '      "internal": true',
  '    }',
  '  ],',
  '  "issues": [',
  '    {',
  '      "crawlId": "550e8400-e29b-41d4-a716-446655440000",',
  '      "url": "https://example.com/about",',
  '      "rule": "missing_meta_description",',
  '      "severity": "warning",',
  '      "detail": null',
  '    }',
  '  ]',
  '}',
  '```',
  '',
  '### 3. Finalize Sync',
  '`POST /api/v1/projects/{project}/crawls/{crawlId}/finalize`',
  '',
  'Accepts optional `issueCounts` (`Array<{ rule, severity, count }>` matching `coqui_core::IssueCount`), seals the `CrawlRun` status, recomputes aggregate totals in PostgreSQL if omitted, and broadcasts `CrawlSynced` over Reverb.',
  '',
].join('\n');

fs.writeFileSync(path.join(DOCS, 'desktop-sync-contract.md'), syncContract);

console.log(
  JSON.stringify(
    {
      epics: epicIssues.length,
      features: featureIssues.length,
      totalIssues: epicIssues.length + featureIssues.length,
      outputs: [
        'docs/development-plan.md',
        'docs/desktop-sync-contract.md',
        'docs/reference/roadmap-matrix.csv',
        'tools/plan/.generated/issues.json',
      ],
    },
    null,
    2,
  ),
);
