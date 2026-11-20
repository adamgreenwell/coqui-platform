import { Head, Link, router } from '@inertiajs/react';
import {
  AlertCircle,
  AlertTriangle,
  ArrowLeft,
  Bot,
  CheckCircle2,
  Clock,
  ExternalLink,
  Flame,
  Globe,
  HelpCircle,
  Layers,
  LineChart,
  RefreshCw,
  Search,
  ShieldAlert,
  Sparkles,
  TrendingUp,
  Zap,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface ProjectSummary {
  id: string;
  name: string;
  domain: string;
  canonical_url: string;
  runnr_site_id?: string | null;
}

interface PagePerformanceKpis {
  total_organic_sessions: number;
  total_ai_referral_sessions: number;
  total_gsc_clicks: number;
  crawled_pages_count: number;
  pages_with_traffic: number;
  at_risk_pages_count: number;
  top_ai_assistant: string | null;
  ai_brand_totals: Record<string, number>;
  traffic_provider: string | null;
}

interface PagePerformanceItem {
  path: string;
  full_url: string;
  crawl_status: number | null;
  issues: string[];
  gsc_clicks: number;
  gsc_impressions: number;
  organic_sessions: number;
  ai_referral_sessions: number;
  total_sessions: number;
  bounce_rate: number;
  avg_engagement_time_sec: number;
  ai_referrals_breakdown: Record<string, number>;
  is_at_risk: boolean;
}

interface AnalyticsIntegration {
  id: string;
  provider: string;
  label: string;
  status: string;
  site: string | null;
  lastSyncedAt: string | null;
}

// Badge colours per analytics provider; unknown providers fall back to slate.
const PROVIDER_BADGE: Record<string, string> = {
  ga4: 'bg-blue-500/10 text-blue-400 border-blue-500/30',
  plausible: 'bg-indigo-500/10 text-indigo-400 border-indigo-500/30',
  umami: 'bg-cyan-500/10 text-cyan-400 border-cyan-500/30',
};

interface PerformanceProps {
  workspace: WorkspaceSummary;
  project: ProjectSummary;
  days: number;
  kpis: PagePerformanceKpis;
  pages: PagePerformanceItem[];
  integrations: AnalyticsIntegration[];
  trafficProviderLabels: Record<string, string>;
  canEdit: boolean;
}

function formatDuration(seconds: number): string {
  if (seconds <= 0) return '0s';
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  if (mins === 0) return `${secs}s`;
  return `${mins}m ${secs}s`;
}

export default function Performance({
  workspace,
  project,
  days,
  kpis,
  pages,
  integrations,
  trafficProviderLabels,
  canEdit,
}: PerformanceProps) {
  const [activeTab, setActiveTab] = useState<'all' | 'at_risk' | 'ai_referrals' | 'organic'>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [syncing, setSyncing] = useState(false);

  const handleSync = () => {
    setSyncing(true);
    router.post(
      `/workspaces/${workspace.id}/projects/${project.id}/analytics/sync`,
      {},
      {
        preserveScroll: true,
        onFinish: () => setSyncing(false),
      }
    );
  };

  const handleDaysChange = (newDays: number) => {
    router.get(
      `/workspaces/${workspace.id}/projects/${project.id}/analytics/performance`,
      { days: newDays },
      { preserveState: true }
    );
  };

  const filteredPages = useMemo(() => {
    return pages.filter((page) => {
      // Tab filter
      if (activeTab === 'at_risk' && !page.is_at_risk) {
        return false;
      }
      if (activeTab === 'ai_referrals' && page.ai_referral_sessions <= 0) {
        return false;
      }
      if (activeTab === 'organic' && page.organic_sessions <= 0 && page.gsc_clicks <= 0) {
        return false;
      }

      // Search query filter
      if (searchQuery.trim() !== '') {
        const query = searchQuery.toLowerCase();
        return (
          page.path.toLowerCase().includes(query) ||
          page.full_url.toLowerCase().includes(query) ||
          page.issues.some((i) => i.toLowerCase().includes(query))
        );
      }

      return true;
    });
  }, [pages, activeTab, searchQuery]);

  return (
    <AppLayout currentWorkspace={workspace}>
      <Head title={`${project.name} - Page Performance & Traffic Attribution`} />

      <div className="space-y-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {/* Navigation Breadcrumb & Header */}
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
                <span className="p-1 rounded bg-teal-500/10 text-teal-400 border border-teal-500/30">
                  <LineChart className="h-3.5 w-3.5" />
                </span>
                <h1 className="text-xl font-bold text-white tracking-tight">
                  Page Performance &amp; Traffic Attribution
                </h1>
                {integrations.map((integration) => (
                  <span
                    key={integration.id}
                    className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-mono border ${PROVIDER_BADGE[integration.provider] ?? 'bg-slate-500/10 text-slate-300 border-slate-500/30'}`}
                  >
                    <CheckCircle2 className="h-3 w-3" /> {integration.label}: {integration.site}
                  </span>
                ))}
                {integrations.length === 0 && (
                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-500/10 text-amber-400 border border-amber-500/30">
                    <AlertTriangle className="h-3 w-3" /> No Analytics Connected
                  </span>
                )}
              </div>
              <p className="text-xs text-slate-400 mt-0.5">
                Unified crawl technical health overlay with Google Search Console clicks and GA4 / Umami / Plausible organic &amp; AI assistant traffic.
              </p>
            </div>
          </div>

          <div className="flex items-center gap-3">
            {/* Days switcher */}
            <div className="flex items-center rounded-lg border border-slate-800 bg-slate-900 p-0.5 text-xs">
              {[7, 14, 30, 90].map((d) => (
                <button
                  key={d}
                  type="button"
                  onClick={() => handleDaysChange(d)}
                  className={`px-3 py-1 rounded-md font-medium transition cursor-pointer ${
                    days === d
                      ? 'bg-slate-800 text-white shadow-sm'
                      : 'text-slate-400 hover:text-slate-200'
                  }`}
                >
                  {d}d
                </button>
              ))}
            </div>

            {canEdit && (
              <button
                type="button"
                onClick={handleSync}
                disabled={syncing}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-teal-500/10 hover:bg-teal-500/20 border border-teal-500/30 text-teal-300 text-xs font-medium transition cursor-pointer disabled:opacity-50"
              >
                <RefreshCw className={`h-3.5 w-3.5 ${syncing ? 'animate-spin' : ''}`} />
                <span>{syncing ? 'Syncing...' : 'Sync Traffic'}</span>
              </button>
            )}
          </div>
        </div>

        {/* KPI Stat Cards */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {/* Organic Sessions */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-4">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-400">Organic Search Sessions</span>
              <span className="p-1.5 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                <TrendingUp className="h-4 w-4" />
              </span>
            </div>
            <div className="mt-2 flex items-baseline gap-2">
              <span className="text-2xl font-bold text-white tracking-tight">
                {kpis.total_organic_sessions.toLocaleString()}
              </span>
              <span className="text-xs text-slate-400">
                ({kpis.total_gsc_clicks.toLocaleString()} GSC clicks)
              </span>
            </div>
            <p className="mt-1 text-[11px] text-slate-400">
              Trailing {days} days search traffic across all landing pages
              {kpis.traffic_provider && ` (from ${trafficProviderLabels[kpis.traffic_provider] ?? kpis.traffic_provider})`}
            </p>
          </div>

          {/* AI Assistant Referrals */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-4">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-400">AI Assistant Referrals</span>
              <span className="p-1.5 rounded-lg bg-purple-500/10 text-purple-400 border border-purple-500/20">
                <Sparkles className="h-4 w-4" />
              </span>
            </div>
            <div className="mt-2 flex items-baseline gap-2">
              <span className="text-2xl font-bold text-white tracking-tight">
                {kpis.total_ai_referral_sessions.toLocaleString()}
              </span>
              {kpis.top_ai_assistant && (
                <span className="text-xs font-medium text-purple-400">
                  Top: {kpis.top_ai_assistant}
                </span>
              )}
            </div>
            <div className="mt-2 flex flex-wrap gap-1">
              {Object.entries(kpis.ai_brand_totals).map(([brand, hits]) => (
                <span
                  key={brand}
                  className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono bg-purple-500/10 text-purple-300 border border-purple-500/20"
                >
                  {brand}: {hits.toLocaleString()}
                </span>
              ))}
              {Object.keys(kpis.ai_brand_totals).length === 0 && (
                <span className="text-[11px] text-slate-500">ChatGPT, Perplexity, Claude, Gemini</span>
              )}
            </div>
          </div>

          {/* High Traffic at Risk */}
          <div className={`rounded-xl border p-4 ${
            kpis.at_risk_pages_count > 0
              ? 'border-rose-500/30 bg-rose-950/20'
              : 'border-slate-800 bg-slate-900/60'
          }`}>
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-400">High-Traffic at Risk</span>
              <span className={`p-1.5 rounded-lg border ${
                kpis.at_risk_pages_count > 0
                  ? 'bg-rose-500/10 text-rose-400 border-rose-500/20'
                  : 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
              }`}>
                <ShieldAlert className="h-4 w-4" />
              </span>
            </div>
            <div className="mt-2 flex items-baseline gap-2">
              <span className={`text-2xl font-bold tracking-tight ${
                kpis.at_risk_pages_count > 0 ? 'text-rose-400' : 'text-white'
              }`}>
                {kpis.at_risk_pages_count}
              </span>
              <span className="text-xs text-slate-400">critical URLs</span>
            </div>
            <p className="mt-1 text-[11px] text-slate-400">
              Pages receiving organic or AI traffic with crawl/status errors
            </p>
          </div>

          {/* Crawled Coverage */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-4">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-400">Active Traffic Pages</span>
              <span className="p-1.5 rounded-lg bg-sky-500/10 text-sky-400 border border-sky-500/20">
                <Globe className="h-4 w-4" />
              </span>
            </div>
            <div className="mt-2 flex items-baseline gap-2">
              <span className="text-2xl font-bold text-white tracking-tight">
                {kpis.pages_with_traffic}
              </span>
              <span className="text-xs text-slate-400">
                / {Math.max(kpis.crawled_pages_count, pages.length)} indexed URLs
              </span>
            </div>
            <p className="mt-1 text-[11px] text-slate-400">
              Distinct URLs generating search impressions or user sessions
            </p>
          </div>
        </div>

        {/* Filter Bar & Search */}
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
          <div className="flex items-center gap-1 p-1 bg-slate-900/80 rounded-xl border border-slate-800 text-xs">
            <button
              type="button"
              onClick={() => setActiveTab('all')}
              className={`px-3 py-1.5 rounded-lg font-medium transition cursor-pointer ${
                activeTab === 'all'
                  ? 'bg-slate-800 text-white shadow-sm'
                  : 'text-slate-400 hover:text-slate-200'
              }`}
            >
              All Pages ({pages.length})
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('at_risk')}
              className={`px-3 py-1.5 rounded-lg font-medium transition cursor-pointer flex items-center gap-1.5 ${
                activeTab === 'at_risk'
                  ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30'
                  : 'text-slate-400 hover:text-rose-400'
              }`}
            >
              <ShieldAlert className="h-3.5 w-3.5" />
              <span>At-Risk Pages ({kpis.at_risk_pages_count})</span>
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('ai_referrals')}
              className={`px-3 py-1.5 rounded-lg font-medium transition cursor-pointer flex items-center gap-1.5 ${
                activeTab === 'ai_referrals'
                  ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30'
                  : 'text-slate-400 hover:text-purple-400'
              }`}
            >
              <Sparkles className="h-3.5 w-3.5" />
              <span>AI Referrals</span>
            </button>
            <button
              type="button"
              onClick={() => setActiveTab('organic')}
              className={`px-3 py-1.5 rounded-lg font-medium transition cursor-pointer flex items-center gap-1.5 ${
                activeTab === 'organic'
                  ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                  : 'text-slate-400 hover:text-emerald-400'
              }`}
            >
              <TrendingUp className="h-3.5 w-3.5" />
              <span>Organic Traffic</span>
            </button>
          </div>

          <div className="relative min-w-[260px]">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-slate-500" />
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Filter by path, URL, or error..."
              className="w-full pl-9 pr-3 py-1.5 rounded-lg bg-slate-900 border border-slate-800 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-slate-700"
            />
          </div>
        </div>

        {/* Unified Performance Table */}
        <div className="rounded-xl border border-slate-800 bg-slate-900/60 overflow-hidden shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-950/60 border-b border-slate-800 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                <tr>
                  <th className="py-3 px-4">Landing Page / URL</th>
                  <th className="py-3 px-3">Crawl Health</th>
                  <th className="py-3 px-3 text-right">GSC Clicks</th>
                  <th className="py-3 px-3 text-right">Organic Sessions</th>
                  <th className="py-3 px-3">AI Assistant Referrals</th>
                  <th className="py-3 px-3 text-right">Total Visits</th>
                  <th className="py-3 px-3 text-right">Bounce Rate</th>
                  <th className="py-3 px-3 text-right">Avg Duration</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/60 text-slate-300">
                {filteredPages.map((page) => (
                  <tr
                    key={page.path}
                    className={`hover:bg-slate-800/30 transition ${
                      page.is_at_risk ? 'bg-rose-950/10' : ''
                    }`}
                  >
                    {/* Path & URL */}
                    <td className="py-3 px-4 max-w-xs sm:max-w-md">
                      <div className="flex items-center gap-2">
                        {page.is_at_risk && (
                          <span
                            title="High Traffic at Risk: Page receives organic or AI visits while having technical crawl issues"
                            className="inline-flex items-center text-rose-400"
                          >
                            <ShieldAlert className="h-4 w-4 shrink-0" />
                          </span>
                        )}
                        <span className="font-mono text-white text-xs truncate">
                          {page.path}
                        </span>
                        <a
                          href={page.full_url}
                          target="_blank"
                          rel="noreferrer"
                          className="text-slate-500 hover:text-slate-300 shrink-0 transition"
                        >
                          <ExternalLink className="h-3 w-3" />
                        </a>
                      </div>
                      {page.issues.length > 0 && (
                        <div className="mt-1 flex flex-wrap gap-1">
                          {page.issues.map((issue) => (
                            <span
                              key={issue}
                              className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-rose-500/10 text-rose-300 border border-rose-500/20"
                            >
                              {issue}
                            </span>
                          ))}
                        </div>
                      )}
                    </td>

                    {/* Crawl Health */}
                    <td className="py-3 px-3 whitespace-nowrap">
                      {page.crawl_status !== null ? (
                        <span
                          className={`inline-flex items-center px-2 py-0.5 rounded text-[11px] font-mono font-medium ${
                            page.crawl_status >= 200 && page.crawl_status < 300
                              ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
                              : page.crawl_status >= 300 && page.crawl_status < 400
                              ? 'bg-sky-500/10 text-sky-400 border border-sky-500/20'
                              : 'bg-rose-500/10 text-rose-400 border border-rose-500/20'
                          }`}
                        >
                          HTTP {page.crawl_status}
                        </span>
                      ) : (
                        <span className="text-slate-500 text-[11px]">—</span>
                      )}
                    </td>

                    {/* GSC Clicks */}
                    <td className="py-3 px-3 text-right font-mono whitespace-nowrap">
                      {page.gsc_clicks > 0 ? (
                        <div>
                          <span className="text-emerald-400 font-semibold">
                            {page.gsc_clicks.toLocaleString()}
                          </span>
                          <span className="block text-[10px] text-slate-500">
                            {page.gsc_impressions.toLocaleString()} imp
                          </span>
                        </div>
                      ) : (
                        <span className="text-slate-500">—</span>
                      )}
                    </td>

                    {/* Organic Sessions */}
                    <td className="py-3 px-3 text-right font-mono whitespace-nowrap">
                      {page.organic_sessions > 0 ? (
                        <span className="text-emerald-400 font-semibold">
                          {page.organic_sessions.toLocaleString()}
                        </span>
                      ) : (
                        <span className="text-slate-500">—</span>
                      )}
                    </td>

                    {/* AI Referrals & Breakdown */}
                    <td className="py-3 px-3">
                      {page.ai_referral_sessions > 0 ? (
                        <div className="space-y-1">
                          <div className="flex items-center gap-1.5 font-mono">
                            <Sparkles className="h-3 w-3 text-purple-400 shrink-0" />
                            <span className="text-purple-300 font-semibold">
                              {page.ai_referral_sessions.toLocaleString()}
                            </span>
                          </div>
                          <div className="flex flex-wrap gap-1">
                            {Object.entries(page.ai_referrals_breakdown).map(([brand, count]) => (
                              <span
                                key={brand}
                                className="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-mono bg-purple-500/10 text-purple-300 border border-purple-500/20"
                              >
                                {brand}: {count}
                              </span>
                            ))}
                          </div>
                        </div>
                      ) : (
                        <span className="text-slate-500 text-[11px]">—</span>
                      )}
                    </td>

                    {/* Total Visits */}
                    <td className="py-3 px-3 text-right font-mono whitespace-nowrap">
                      {page.total_sessions > 0 ? (
                        <span className="text-white font-medium">
                          {page.total_sessions.toLocaleString()}
                        </span>
                      ) : (
                        <span className="text-slate-500">—</span>
                      )}
                    </td>

                    {/* Bounce Rate */}
                    <td className="py-3 px-3 text-right font-mono whitespace-nowrap">
                      {page.total_sessions > 0 ? (
                        <span className={page.bounce_rate > 70 ? 'text-amber-400' : 'text-slate-300'}>
                          {page.bounce_rate.toFixed(1)}%
                        </span>
                      ) : (
                        <span className="text-slate-500">—</span>
                      )}
                    </td>

                    {/* Avg Duration */}
                    <td className="py-3 px-3 text-right font-mono whitespace-nowrap text-slate-400">
                      {page.total_sessions > 0 ? (
                        formatDuration(page.avg_engagement_time_sec)
                      ) : (
                        <span className="text-slate-500">—</span>
                      )}
                    </td>
                  </tr>
                ))}

                {filteredPages.length === 0 && (
                  <tr>
                    <td colSpan={8} className="py-12 text-center text-slate-500">
                      <div className="flex flex-col items-center justify-center gap-2">
                        <AlertCircle className="h-6 w-6 text-slate-600" />
                        <p className="text-sm font-medium text-slate-400">No matching pages found</p>
                        <p className="text-xs text-slate-500 max-w-sm">
                          {searchQuery
                            ? 'Try refining your search filter.'
                            : 'Sync your GA4, Umami or Plausible analytics or run a crawl to generate performance records.'}
                        </p>
                      </div>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
