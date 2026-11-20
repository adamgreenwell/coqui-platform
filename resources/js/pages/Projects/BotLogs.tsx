import { Head, Link, router } from '@inertiajs/react';
import {
  Activity,
  AlertTriangle,
  ArrowLeft,
  Bot,
  Check,
  CheckCircle2,
  Clock,
  Compass,
  CornerDownRight,
  ExternalLink,
  Flame,
  Globe,
  Radio,
  RefreshCw,
  Search,
  Server,
  ShieldAlert,
  Sparkles,
  Zap,
} from 'lucide-react';
import { useState } from 'react';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface ProjectSummary {
  id: string;
  name: string;
  domain: string;
  canonical_url: string;
  runnr_site_id?: string | null;
}

interface TelemetryKpis {
  totalHits: number;
  searchEngineHits: number;
  aiCrawlerHits: number;
  wastedHits: number;
  wastePercentage: number;
  bytesServed: number;
  errorRate: number;
}

interface DailyRecord {
  date: string;
  searchEngineHits: number;
  aiCrawlerHits: number;
  wastedHits: number;
  totalHits: number;
}

interface BotFamilyRecord {
  botFamily: string;
  category: string;
  hits: number;
  percent: number;
  status2xx: number;
  status3xx: number;
  status4xx: number;
  status5xx: number;
  errorRate: number;
  bytesServed: number;
}

interface StatusBreakdown {
  status2xx: number;
  status3xx: number;
  status4xx: number;
  status5xx: number;
}

interface Flagged404 {
  path: string;
  hits: number;
  lastHitAt: string;
  bots: string[];
  recommendedTarget: string;
}

interface BotLogsProps {
  workspace: WorkspaceSummary;
  project: ProjectSummary;
  days: number;
  kpis: TelemetryKpis;
  dailyTimeseries: DailyRecord[];
  botFamilyBreakdown: BotFamilyRecord[];
  statusBreakdown: StatusBreakdown;
  flagged404s: Flagged404[];
  canEdit: boolean;
}

function formatBytes(bytes: number): string {
  if (bytes <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  return `${(bytes / Math.pow(1024, i)).toFixed(1)} ${units[i]}`;
}

export default function BotLogs({
  workspace,
  project,
  days,
  kpis,
  dailyTimeseries,
  botFamilyBreakdown,
  statusBreakdown,
  flagged404s,
  canEdit,
}: BotLogsProps) {
  const [targetOverrides, setTargetOverrides] = useState<Record<string, string>>({});
  const [remediatedPaths, setRemediatedPaths] = useState<Record<string, boolean>>({});

  const handleRemediate = (sourcePath: string, defaultTarget: string) => {
    const target = targetOverrides[sourcePath] ?? defaultTarget;
    router.post(
      `/workspaces/${workspace.id}/projects/${project.id}/bot-logs/remediate`,
      {
        source_path: sourcePath,
        target_path: target,
      },
      {
        preserveScroll: true,
        onSuccess: () => {
          setRemediatedPaths((prev) => ({ ...prev, [sourcePath]: true }));
        },
      }
    );
  };

  return (
    <AppLayout currentWorkspace={workspace}>
      <Head title={`${project.name} - Bot Telemetry & Crawl Budget`} />

      <div className="space-y-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {/* Navigation Breadcrumb */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <Link
              href={`/workspaces/${workspace.id}/projects/${project.id}`}
              className="p-2 rounded-lg bg-slate-900 border border-slate-800 text-slate-400 hover:text-white hover:border-slate-700 transition cursor-pointer"
            >
              <ArrowLeft className="h-4 w-4" />
            </Link>
            <div>
              <div className="flex items-center gap-2">
                <span className="p-1 rounded bg-indigo-500/10 text-indigo-400 border border-indigo-500/30">
                  <Server className="h-3.5 w-3.5" />
                </span>
                <h1 className="text-xl font-bold text-white tracking-tight">Bot Telemetry &amp; Crawl Budget</h1>
                {project.runnr_site_id ? (
                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-mono bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                    <CheckCircle2 className="h-3 w-3" /> Runnr: {project.runnr_site_id}
                  </span>
                ) : (
                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-500/10 text-amber-400 border border-amber-500/30">
                    <AlertTriangle className="h-3 w-3" /> Standalone Telemetry
                  </span>
                )}
              </div>
              <p className="text-xs text-slate-400 mt-0.5">
                Edge server log ingestion tracking Googlebot, Bingbot, and AI bots (GPTBot, ClaudeBot, PerplexityBot) with automated 404 remediation.
              </p>
            </div>
          </div>

          {/* Window Switcher */}
          <div className="flex items-center gap-1 bg-slate-900 border border-slate-800 rounded-lg p-1 text-xs">
            {[7, 14, 30].map((d) => (
              <button
                key={d}
                type="button"
                onClick={() => router.get(`/workspaces/${workspace.id}/projects/${project.id}/bot-logs`, { days: d })}
                className={`px-3 py-1.5 rounded-md font-medium transition cursor-pointer ${
                  days === d
                    ? 'bg-indigo-600 text-white shadow-sm'
                    : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60'
                }`}
              >
                {d} Days
              </button>
            ))}
          </div>
        </div>

        {/* KPI Cards Grid */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {/* Search Engine Hits */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-4 space-y-2">
            <div className="flex items-center justify-between text-xs text-slate-400">
              <span className="font-medium">Search Engine Crawlers</span>
              <Search className="h-4 w-4 text-emerald-400" />
            </div>
            <div className="flex items-baseline justify-between">
              <span className="text-2xl font-bold text-white tracking-tight">
                {kpis.searchEngineHits.toLocaleString()}
              </span>
              <span className="text-xs font-mono text-emerald-400">
                {kpis.totalHits > 0 ? ((kpis.searchEngineHits / kpis.totalHits) * 100).toFixed(1) : 0}%
              </span>
            </div>
            <p className="text-[11px] text-slate-500">Googlebot, Bingbot, Yandex &amp; DuckDuckGo</p>
          </div>

          {/* AI Bot Hits */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-4 space-y-2">
            <div className="flex items-center justify-between text-xs text-slate-400">
              <span className="font-medium">AI &amp; LLM Bots</span>
              <Bot className="h-4 w-4 text-sky-400" />
            </div>
            <div className="flex items-baseline justify-between">
              <span className="text-2xl font-bold text-white tracking-tight">
                {kpis.aiCrawlerHits.toLocaleString()}
              </span>
              <span className="text-xs font-mono text-sky-400">
                {kpis.totalHits > 0 ? ((kpis.aiCrawlerHits / kpis.totalHits) * 100).toFixed(1) : 0}%
              </span>
            </div>
            <p className="text-[11px] text-slate-500">GPTBot, ClaudeBot, Perplexity &amp; Meta</p>
          </div>

          {/* Crawl Budget Waste */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-4 space-y-2">
            <div className="flex items-center justify-between text-xs text-slate-400">
              <span className="font-medium">Wasted Crawl Budget</span>
              <Flame className="h-4 w-4 text-rose-400" />
            </div>
            <div className="flex items-baseline justify-between">
              <span className="text-2xl font-bold text-rose-300 tracking-tight">
                {kpis.wastedHits.toLocaleString()}
              </span>
              <span className="text-xs font-mono text-rose-400">
                {kpis.wastePercentage}% waste
              </span>
            </div>
            <p className="text-[11px] text-slate-500">
              {statusBreakdown.status4xx} on 4xx • {statusBreakdown.status5xx} on 5xx errors
            </p>
          </div>

          {/* Bandwidth Served */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-4 space-y-2">
            <div className="flex items-center justify-between text-xs text-slate-400">
              <span className="font-medium">Bot Data Transfer</span>
              <Radio className="h-4 w-4 text-purple-400" />
            </div>
            <div className="flex items-baseline justify-between">
              <span className="text-2xl font-bold text-white tracking-tight">
                {formatBytes(kpis.bytesServed)}
              </span>
              <span className="text-xs font-mono text-slate-400">
                {kpis.totalHits.toLocaleString()} hits
              </span>
            </div>
            <p className="text-[11px] text-slate-500">Total edge bandwidth delivered to bots</p>
          </div>
        </div>

        {/* Daily Crawl Activity Timeseries Table */}
        <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-5 space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-sm font-semibold text-white">Daily Bot Activity Timeline</h2>
              <p className="text-xs text-slate-400">
                Daily crawler distribution across search engines, AI models, and error responses.
              </p>
            </div>
            <span className="text-xs font-mono text-slate-400">
              Last {days} Days • Total: {kpis.totalHits.toLocaleString()}
            </span>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-950/60 text-slate-400 uppercase text-[10px] tracking-wider border-b border-slate-800">
                <tr>
                  <th className="px-4 py-2.5">Date</th>
                  <th className="px-4 py-2.5 text-right">Search Engines</th>
                  <th className="px-4 py-2.5 text-right">AI Bots</th>
                  <th className="px-4 py-2.5 text-right">Wasted (4xx/5xx)</th>
                  <th className="px-4 py-2.5 text-right">Total Hits</th>
                  <th className="px-4 py-2.5">Distribution Bar</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/60 text-slate-300">
                {dailyTimeseries.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-8 text-center text-slate-500">
                      No bot activity recorded in the last {days} days.
                    </td>
                  </tr>
                ) : (
                  dailyTimeseries.map((day) => {
                    const searchPct = day.totalHits > 0 ? (day.searchEngineHits / day.totalHits) * 100 : 0;
                    const aiPct = day.totalHits > 0 ? (day.aiCrawlerHits / day.totalHits) * 100 : 0;
                    const wastePct = day.totalHits > 0 ? (day.wastedHits / day.totalHits) * 100 : 0;

                    return (
                      <tr key={day.date} className="hover:bg-slate-800/30 transition">
                        <td className="px-4 py-2.5 font-mono text-slate-200">{day.date}</td>
                        <td className="px-4 py-2.5 text-right font-mono text-emerald-400">
                          {day.searchEngineHits.toLocaleString()}
                        </td>
                        <td className="px-4 py-2.5 text-right font-mono text-sky-400">
                          {day.aiCrawlerHits.toLocaleString()}
                        </td>
                        <td className="px-4 py-2.5 text-right font-mono text-rose-400">
                          {day.wastedHits.toLocaleString()}
                        </td>
                        <td className="px-4 py-2.5 text-right font-mono font-semibold text-white">
                          {day.totalHits.toLocaleString()}
                        </td>
                        <td className="px-4 py-2.5 w-64">
                          {day.totalHits > 0 ? (
                            <div className="flex h-2 w-full rounded-full bg-slate-800 overflow-hidden">
                              <div style={{ width: `${searchPct}%` }} className="bg-emerald-500" title={`Search: ${searchPct.toFixed(1)}%`} />
                              <div style={{ width: `${aiPct}%` }} className="bg-sky-500" title={`AI: ${aiPct.toFixed(1)}%`} />
                              <div style={{ width: `${wastePct}%` }} className="bg-rose-500" title={`Waste: ${wastePct.toFixed(1)}%`} />
                            </div>
                          ) : (
                            <div className="h-2 w-full rounded-full bg-slate-800/40" />
                          )}
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>

        {/* Bot Family Directory & Crawl Breakdown */}
        <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-5 space-y-4">
          <div>
            <h2 className="text-sm font-semibold text-white">Crawler Family Breakdown</h2>
            <p className="text-xs text-slate-400">
              Detailed hit volumes, error rates, and HTTP status code distribution per crawler.
            </p>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-950/60 text-slate-400 uppercase text-[10px] tracking-wider border-b border-slate-800">
                <tr>
                  <th className="px-4 py-2.5">Bot Family</th>
                  <th className="px-4 py-2.5">Category</th>
                  <th className="px-4 py-2.5 text-right">Hits</th>
                  <th className="px-4 py-2.5 text-right">Share</th>
                  <th className="px-4 py-2.5 text-right">2xx OK</th>
                  <th className="px-4 py-2.5 text-right">3xx Redirect</th>
                  <th className="px-4 py-2.5 text-right">4xx Client</th>
                  <th className="px-4 py-2.5 text-right">5xx Server</th>
                  <th className="px-4 py-2.5 text-right">Error Rate</th>
                  <th className="px-4 py-2.5 text-right">Bytes</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/60 text-slate-300">
                {botFamilyBreakdown.length === 0 ? (
                  <tr>
                    <td colSpan={10} className="px-4 py-8 text-center text-slate-500">
                      No crawler summaries ingested yet. Runnr server logs ingest automatically each hour.
                    </td>
                  </tr>
                ) : (
                  botFamilyBreakdown.map((bot) => (
                    <tr key={bot.botFamily} className="hover:bg-slate-800/30 transition">
                      <td className="px-4 py-2.5 font-mono font-medium text-white flex items-center gap-2">
                        {bot.category === 'search_engine' ? (
                          <Search className="h-3.5 w-3.5 text-emerald-400" />
                        ) : bot.category === 'ai_crawler' ? (
                          <Bot className="h-3.5 w-3.5 text-sky-400" />
                        ) : (
                          <Compass className="h-3.5 w-3.5 text-slate-400" />
                        )}
                        <span>{bot.botFamily}</span>
                      </td>
                      <td className="px-4 py-2.5">
                        {bot.category === 'search_engine' ? (
                          <span className="px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                            Search Engine
                          </span>
                        ) : bot.category === 'ai_crawler' ? (
                          <span className="px-2 py-0.5 rounded-full text-[10px] font-medium bg-sky-500/10 text-sky-400 border border-sky-500/30">
                            AI Crawler
                          </span>
                        ) : (
                          <span className="px-2 py-0.5 rounded-full text-[10px] font-medium bg-slate-800 text-slate-400 border border-slate-700">
                            Other
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-2.5 text-right font-mono text-white font-semibold">
                        {bot.hits.toLocaleString()}
                      </td>
                      <td className="px-4 py-2.5 text-right font-mono text-slate-300">
                        {bot.percent}%
                      </td>
                      <td className="px-4 py-2.5 text-right font-mono text-emerald-400">
                        {bot.status2xx.toLocaleString()}
                      </td>
                      <td className="px-4 py-2.5 text-right font-mono text-amber-400">
                        {bot.status3xx.toLocaleString()}
                      </td>
                      <td className="px-4 py-2.5 text-right font-mono text-rose-400">
                        {bot.status4xx.toLocaleString()}
                      </td>
                      <td className="px-4 py-2.5 text-right font-mono text-red-500">
                        {bot.status5xx.toLocaleString()}
                      </td>
                      <td className="px-4 py-2.5 text-right font-mono font-medium">
                        <span className={bot.errorRate > 5 ? 'text-rose-400' : 'text-slate-400'}>
                          {bot.errorRate}%
                        </span>
                      </td>
                      <td className="px-4 py-2.5 text-right font-mono text-slate-400">
                        {formatBytes(bot.bytesServed)}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>

        {/* Automated 404 Remediation & Crawl Budget Reclamation */}
        <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-5 space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
              <div className="flex items-center gap-2">
                <ShieldAlert className="h-4 w-4 text-rose-400" />
                <h2 className="text-sm font-semibold text-white">Automated 404 Redirect Remediation</h2>
              </div>
              <p className="text-xs text-slate-400 mt-0.5">
                High-frequency dead URLs crawled by Googlebot and AI bots. Configure 301 redirects to recover crawl budget and salvage SEO link equity.
              </p>
            </div>
            <span className="text-xs font-mono text-slate-400">
              {flagged404s.length} Flagged Paths
            </span>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-950/60 text-slate-400 uppercase text-[10px] tracking-wider border-b border-slate-800">
                <tr>
                  <th className="px-4 py-2.5">Dead 404 Path</th>
                  <th className="px-4 py-2.5 text-right">Bot Hits</th>
                  <th className="px-4 py-2.5">Crawlers Impacted</th>
                  <th className="px-4 py-2.5">Suggested 301 Destination</th>
                  <th className="px-4 py-2.5 text-right">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/60 text-slate-300">
                {flagged404s.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-4 py-8 text-center text-slate-500">
                      Zero high-frequency 404 paths detected. Your crawl budget is clean!
                    </td>
                  </tr>
                ) : (
                  flagged404s.map((item) => {
                    const isRemediated = remediatedPaths[item.path];
                    const currentTarget = targetOverrides[item.path] ?? item.recommendedTarget;

                    return (
                      <tr key={item.path} className="hover:bg-slate-800/30 transition">
                        <td className="px-4 py-2.5 font-mono text-rose-300 max-w-xs truncate">
                          {item.path}
                        </td>
                        <td className="px-4 py-2.5 text-right font-mono font-bold text-white">
                          {item.hits.toLocaleString()}
                        </td>
                        <td className="px-4 py-2.5">
                          <div className="flex flex-wrap gap-1">
                            {item.bots.map((b) => (
                              <span
                                key={b}
                                className="px-1.5 py-0.5 rounded text-[10px] font-mono bg-slate-800 text-slate-300 border border-slate-700"
                              >
                                {b}
                              </span>
                            ))}
                          </div>
                        </td>
                        <td className="px-4 py-2.5">
                          <div className="flex items-center gap-1.5">
                            <CornerDownRight className="h-3.5 w-3.5 text-slate-500 shrink-0" />
                            <input
                              type="text"
                              value={currentTarget}
                              disabled={isRemediated || !canEdit}
                              onChange={(e) =>
                                setTargetOverrides((prev) => ({
                                  ...prev,
                                  [item.path]: e.target.value,
                                }))
                              }
                              className="w-48 sm:w-64 px-2 py-1 rounded bg-slate-950 border border-slate-700 text-xs text-white font-mono focus:outline-none focus:ring-1 focus:ring-indigo-500 disabled:opacity-60"
                              placeholder="/destination-url"
                            />
                          </div>
                        </td>
                        <td className="px-4 py-2.5 text-right">
                          {isRemediated ? (
                            <span className="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-400">
                              <Check className="h-3 w-3" /> Configured
                            </span>
                          ) : canEdit ? (
                            <button
                              type="button"
                              onClick={() => handleRemediate(item.path, item.recommendedTarget)}
                              className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-indigo-600 hover:bg-indigo-500 text-white font-medium text-[11px] transition cursor-pointer shadow-sm shadow-indigo-500/20"
                            >
                              <span>Create 301</span>
                            </button>
                          ) : null}
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
