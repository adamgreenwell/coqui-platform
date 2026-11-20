import { Head, Link, router, useForm } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowDown,
  ArrowLeft,
  ArrowUp,
  BarChart3,
  Calendar,
  CheckCircle2,
  ChevronRight,
  Clock,
  ExternalLink,
  FileText,
  Filter,
  Globe,
  History,
  Laptop,
  Layers,
  MapPin,
  Minus,
  Plus,
  RefreshCw,
  Search,
  Smartphone,
  Sparkles,
  Star,
  Trash2,
  TrendingDown,
  TrendingUp,
  Users,
  X,
} from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';
import { getEcho } from '../../echo';
import { FeatureLink, UpgradeNotice, usePlan } from '../../components/PlanGate';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface ProjectDetails {
  id: string;
  name: string;
  domain: string;
  canonical_url: string;
  location_code: number;
  language_code: string;
}

interface CompetitorItem {
  id: string;
  domain: string;
  label?: string | null;
  sort_order: number;
}

interface CompetitorRankData {
  rank: number | null;
  url?: string | null;
  has_ai_overview: boolean;
  has_featured_snippet: boolean;
}

interface SearchPerformanceOverlay {
  clicks?: number | null;
  impressions?: number | null;
  ctr?: number | null;
  position?: number | null;
}

interface TrackedKeywordItem {
  id: string;
  keyword: string;
  device: string;
  location_code: number;
  language_code: string;
  cadence: string;
  search_volume?: number | null;
  keyword_difficulty?: number | null;
  is_active: boolean;
  last_checked_at?: string | null;
  current_rank?: number | null;
  previous_rank?: number | null;
  rank_delta?: number | null;
  delta_type: 'up' | 'down' | 'unchanged' | 'new' | 'dropped' | 'none';
  ranking_url?: string | null;
  ranking_title?: string | null;
  has_ai_overview: boolean;
  has_featured_snippet: boolean;
  serp_features?: {
    page_features?: string[];
    ai_overview_cited?: boolean;
    featured_snippet_owned?: boolean;
    item_type?: string | null;
  } | null;
  competitor_ranks: Record<string, CompetitorRankData>;
  gsc_performance?: SearchPerformanceOverlay | null;
  bing_performance?: SearchPerformanceOverlay | null;
}

interface VisibilityMetrics {
  total_keywords: number;
  top_3: number;
  top_10: number;
  top_100: number;
  avg_rank: number | null;
  visibility_score: number;
}

interface HistorySnapshot {
  id: number;
  domain: string;
  rank_group?: number | null;
  rank_absolute?: number | null;
  ranking_url?: string | null;
  ranking_title?: string | null;
  has_ai_overview: boolean;
  has_featured_snippet: boolean;
  checked_on: string;
}

interface IntegrationSummary {
  status: 'connected' | 'needs_reconnect' | 'not_connected';
  site_url?: string | null;
  last_synced_at?: string | null;
  error_message?: string | null;
  connect_url?: string;
}

interface KeywordsIndexProps {
  workspace: WorkspaceSummary;
  project: ProjectDetails;
  competitors: CompetitorItem[];
  keywords: TrackedKeywordItem[];
  metrics: VisibilityMetrics;
  filters: {
    device?: string | null;
    search?: string | null;
  };
  canEdit: boolean;
  gscIntegration?: IntegrationSummary;
  bingIntegration?: IntegrationSummary;
}

function formatRelativeTime(dateString?: string | null): string {
  if (!dateString) return 'Never';
  try {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffSecs = Math.floor(diffMs / 1000);
    const diffMins = Math.floor(diffSecs / 60);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffSecs < 60) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    return `${diffDays}d ago`;
  } catch {
    return dateString;
  }
}

export default function KeywordsIndex({
  workspace,
  project,
  competitors,
  keywords,
  metrics,
  filters,
  canEdit,
  gscIntegration,
  bingIntegration,
}: KeywordsIndexProps) {
  const hasTrafficAttribution = usePlan().has('traffic-attribution');
  const [deviceFilter, setDeviceFilter] = useState<string>(filters.device || 'all');
  const [searchQuery, setSearchQuery] = useState<string>(filters.search || '');
  const [overlayProvider, setOverlayProvider] = useState<'off' | 'gsc' | 'bing'>(
    gscIntegration?.status === 'connected' ? 'gsc' : bingIntegration?.status === 'connected' ? 'bing' : 'off'
  );
  const [addModalOpen, setAddModalOpen] = useState(false);
  const [historyModalKeyword, setHistoryModalKeyword] = useState<TrackedKeywordItem | null>(null);
  const [historySnapshots, setHistorySnapshots] = useState<HistorySnapshot[]>([]);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [livePulse, setLivePulse] = useState(false);

  const addForm = useForm({
    keywords: '',
    device: 'desktop',
    cadence: 'weekly',
  });

  // Reverb Realtime listener for SERP batches
  useEffect(() => {
    const echo = getEcho();
    if (!echo) return;

    const channel = echo.private(`project.${project.id}`);
    channel.listen('.SerpBatchCompleted', (e: { updated_count: number }) => {
      setLivePulse(true);
      setTimeout(() => setLivePulse(false), 2500);
      router.reload({ only: ['keywords', 'metrics'] });
    });

    return () => {
      channel.stopListening('.SerpBatchCompleted');
    };
  }, [project.id]);

  const handleFilterChange = (newDevice: string, newSearch: string) => {
    setDeviceFilter(newDevice);
    router.get(
      `/workspaces/${workspace.id}/projects/${project.id}/keywords`,
      {
        device: newDevice !== 'all' ? newDevice : undefined,
        search: newSearch.trim() !== '' ? newSearch.trim() : undefined,
      },
      { preserveState: true, replace: true }
    );
  };

  const handleAddSubmit = (e: FormEvent) => {
    e.preventDefault();
    addForm.post(`/workspaces/${workspace.id}/projects/${project.id}/keywords`, {
      onSuccess: () => {
        setAddModalOpen(false);
        addForm.reset();
      },
    });
  };

  const handleDeleteKeyword = (kw: TrackedKeywordItem) => {
    if (confirm(`Remove keyword "${kw.keyword}" (${kw.device}) from tracking?`)) {
      router.delete(`/workspaces/${workspace.id}/projects/${project.id}/keywords/${kw.id}`, {
        preserveScroll: true,
      });
    }
  };

  const openHistory = async (kw: TrackedKeywordItem) => {
    setHistoryModalKeyword(kw);
    setHistoryLoading(true);
    try {
      const resp = await fetch(
        `/workspaces/${workspace.id}/projects/${project.id}/keywords/${kw.id}/snapshots`,
        {
          headers: { credentials: 'same-origin', Accept: 'application/json' },
        }
      );
      if (resp.ok) {
        const data = await resp.json();
        setHistorySnapshots(data.snapshots || []);
      }
    } catch {
      // Fallback
    } finally {
      setHistoryLoading(false);
    }
  };

  return (
    <AppLayout currentWorkspace={workspace}>
      <Head title={`${project.name} - Rank Tracker & SERP Intelligence`} />

      <div className="space-y-6">
        {/* Breadcrumb Navigation */}
        <div className="flex items-center gap-2 text-xs text-slate-400">
          <Link href={`/workspaces/${workspace.id}`} className="hover:text-white transition">
            {workspace.name}
          </Link>
          <span>/</span>
          <Link
            href={`/workspaces/${workspace.id}/projects/${project.id}`}
            className="hover:text-white transition"
          >
            {project.name}
          </Link>
          <span>/</span>
          <span className="text-white font-medium">Rank Tracker</span>
        </div>

        {/* Page Header */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-slate-800 pb-6">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold tracking-tight text-white">
                Rank Tracker & SERP Intelligence
              </h1>
              {livePulse && (
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 animate-pulse">
                  <RefreshCw className="h-3 w-3 animate-spin" /> Live update received
                </span>
              )}
            </div>
            <p className="mt-1 text-xs text-slate-400">
              Multi-device rank positions, AI Overview citations, Featured Snippets, and competitor benchmarks for{' '}
              <span className="text-slate-200 font-mono font-medium">{project.domain}</span>.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <Link
              href={`/workspaces/${workspace.id}/projects/${project.id}`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer"
            >
              <ArrowLeft className="h-3.5 w-3.5 text-slate-400" />
              <span>Project Overview</span>
            </Link>

            <Link
              href={`/workspaces/${workspace.id}/projects/${project.id}/keyword-explorer`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-sky-500/10 hover:bg-sky-500/20 border border-sky-500/30 text-sky-300 text-xs font-medium transition cursor-pointer"
            >
              <Sparkles className="h-3.5 w-3.5 text-sky-400" />
              <span>Keyword Explorer</span>
            </Link>

            <Link
              href={`/workspaces/${workspace.id}/projects/${project.id}/keyword-gap`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-indigo-500/10 hover:bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 text-xs font-medium transition cursor-pointer"
            >
              <Users className="h-3.5 w-3.5 text-indigo-400" />
              <span>Keyword Gap</span>
            </Link>

            <FeatureLink
              feature="content-briefs"
              href={`/workspaces/${workspace.id}/projects/${project.id}/keywords/brief`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-teal-500/10 hover:bg-teal-500/20 border border-teal-500/30 text-teal-300 text-xs font-medium transition cursor-pointer"
            >
              <FileText className="h-3.5 w-3.5 text-teal-400" />
              <span>Content Brief</span>
            </FeatureLink>

            {canEdit && (
              <button
                type="button"
                onClick={() => setAddModalOpen(true)}
                className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition shadow-lg shadow-emerald-500/10 cursor-pointer"
              >
                <Plus className="h-4 w-4" />
                <span>Track Keywords</span>
              </button>
            )}
          </div>
        </div>

        {/* Visibility Metrics Banner */}
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
            <span className="text-slate-400 text-xs font-medium">Total Keywords</span>
            <div className="mt-1.5 text-xl font-bold text-white font-mono">
              {metrics.total_keywords}
            </div>
            <span className="text-[11px] text-slate-500">Tracked queries</span>
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
            <span className="text-emerald-400 text-xs font-medium flex items-center gap-1">
              <Star className="h-3 w-3" /> Top 3
            </span>
            <div className="mt-1.5 text-xl font-bold text-emerald-400 font-mono">
              {metrics.top_3}
            </div>
            <span className="text-[11px] text-slate-500">
              {metrics.total_keywords > 0
                ? `${Math.round((metrics.top_3 / metrics.total_keywords) * 100)}% of total`
                : '0%'}
            </span>
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
            <span className="text-sky-400 text-xs font-medium">Top 10</span>
            <div className="mt-1.5 text-xl font-bold text-white font-mono">{metrics.top_10}</div>
            <span className="text-[11px] text-slate-500">
              {metrics.total_keywords > 0
                ? `${Math.round((metrics.top_10 / metrics.total_keywords) * 100)}% page 1`
                : '0%'}
            </span>
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
            <span className="text-indigo-400 text-xs font-medium">Top 100</span>
            <div className="mt-1.5 text-xl font-bold text-white font-mono">{metrics.top_100}</div>
            <span className="text-[11px] text-slate-500">Ranking in SERP</span>
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
            <span className="text-slate-400 text-xs font-medium">Avg Position</span>
            <div className="mt-1.5 text-xl font-bold text-white font-mono">
              {metrics.avg_rank !== null ? `#${metrics.avg_rank}` : '—'}
            </div>
            <span className="text-[11px] text-slate-500">Ranked queries</span>
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
            <span className="text-amber-400 text-xs font-medium flex items-center gap-1">
              <BarChart3 className="h-3 w-3" /> Visibility
            </span>
            <div className="mt-1.5 text-xl font-bold text-amber-400 font-mono">
              {metrics.visibility_score}%
            </div>
            <div className="mt-1 w-full bg-slate-800 h-1.5 rounded-full overflow-hidden">
              <div
                className="bg-amber-400 h-full rounded-full transition-all duration-500"
                style={{ width: `${Math.min(100, metrics.visibility_score)}%` }}
              />
            </div>
          </div>
        </div>

        {/* The GSC/Bing overlay is part of traffic attribution. */}
        {!hasTrafficAttribution && (
          <UpgradeNotice
            compact
            title="Google Search Console & Bing overlay"
            description="Blend real clicks, impressions, CTR and average position from Search Console and Bing into every tracked keyword. Upgrade this workspace to connect them."
          />
        )}

        {/* Actionable Reconnect Banner for Google Search Console */}
        {hasTrafficAttribution && gscIntegration?.status === 'needs_reconnect' && (
          <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div className="flex items-start gap-3">
              <AlertTriangle className="h-5 w-5 text-amber-400 shrink-0 mt-0.5" />
              <div>
                <h4 className="text-sm font-semibold text-amber-200">
                  Google Search Console Reconnection Required
                </h4>
                <p className="text-xs text-amber-300/80 mt-0.5">
                  The Google Search Console authorization token for this project has expired or was revoked. Reconnect your account to restore first-party search analytics and rank overlay sync.
                </p>
                {gscIntegration.error_message && (
                  <p className="text-[11px] font-mono text-amber-400/90 mt-1">
                    Error: {gscIntegration.error_message}
                  </p>
                )}
              </div>
            </div>
            {canEdit && gscIntegration.connect_url && (
              <a
                href={gscIntegration.connect_url}
                className="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-lg bg-amber-500 hover:bg-amber-400 text-slate-950 font-semibold text-xs transition shrink-0 cursor-pointer"
              >
                <RefreshCw className="h-3.5 w-3.5" />
                <span>Reconnect Search Console</span>
              </a>
            )}
          </div>
        )}

        {/* Filter and Search Bar */}
        <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-slate-900/60 border border-slate-800 rounded-xl p-3">
          {/* Device Tabs */}
          <div className="flex items-center gap-1 bg-slate-950/70 p-1 rounded-lg border border-slate-800/80">
            <button
              type="button"
              onClick={() => handleFilterChange('all', searchQuery)}
              className={`px-3 py-1 rounded text-xs font-medium transition cursor-pointer ${
                deviceFilter === 'all'
                  ? 'bg-slate-800 text-white shadow-sm'
                  : 'text-slate-400 hover:text-white'
              }`}
            >
              All Devices
            </button>
            <button
              type="button"
              onClick={() => handleFilterChange('desktop', searchQuery)}
              className={`flex items-center gap-1.5 px-3 py-1 rounded text-xs font-medium transition cursor-pointer ${
                deviceFilter === 'desktop'
                  ? 'bg-slate-800 text-white shadow-sm'
                  : 'text-slate-400 hover:text-white'
              }`}
            >
              <Laptop className="h-3 w-3" /> Desktop
            </button>
            <button
              type="button"
              onClick={() => handleFilterChange('mobile', searchQuery)}
              className={`flex items-center gap-1.5 px-3 py-1 rounded text-xs font-medium transition cursor-pointer ${
                deviceFilter === 'mobile'
                  ? 'bg-slate-800 text-white shadow-sm'
                  : 'text-slate-400 hover:text-white'
              }`}
            >
              <Smartphone className="h-3 w-3" /> Mobile
            </button>
          </div>

          <div className="flex items-center gap-2 flex-1 justify-end">
            {/* First-Party Search Overlay Selector */}
            <div className="flex items-center gap-1 bg-slate-950 p-1 rounded-lg border border-slate-800">
              <button
                type="button"
                onClick={() => setOverlayProvider(overlayProvider === 'gsc' ? 'off' : 'gsc')}
                className={`flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-medium transition cursor-pointer ${
                  overlayProvider === 'gsc'
                    ? 'bg-blue-500/20 text-blue-300 border border-blue-500/40 shadow-sm'
                    : 'text-slate-400 hover:text-white border border-transparent'
                }`}
                title="Google Search Console 28-day performance overlay"
              >
                <BarChart3 className="h-3 w-3" />
                <span>GSC</span>
                {gscIntegration?.status === 'connected' && (
                  <span className="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                )}
              </button>

              <button
                type="button"
                onClick={() => setOverlayProvider(overlayProvider === 'bing' ? 'off' : 'bing')}
                className={`flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-medium transition cursor-pointer ${
                  overlayProvider === 'bing'
                    ? 'bg-sky-500/20 text-sky-300 border border-sky-500/40 shadow-sm'
                    : 'text-slate-400 hover:text-white border border-transparent'
                }`}
                title="Bing Webmaster Tools performance overlay"
              >
                <Globe className="h-3 w-3" />
                <span>Bing</span>
                {bingIntegration?.status === 'connected' && (
                  <span className="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                )}
              </button>
            </div>

            {/* Search box */}
            <div className="relative w-full max-w-xs">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-slate-500" />
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => {
                  setSearchQuery(e.target.value);
                  handleFilterChange(deviceFilter, e.target.value);
                }}
                placeholder="Search tracked keywords..."
                className="w-full pl-9 pr-3 py-1.5 rounded-lg bg-slate-950 border border-slate-800 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 transition"
              />
              {searchQuery && (
                <button
                  type="button"
                  onClick={() => {
                    setSearchQuery('');
                    handleFilterChange(deviceFilter, '');
                  }}
                  className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-500 hover:text-white"
                >
                  <X className="h-3 w-3" />
                </button>
              )}
            </div>
          </div>
        </div>

        {/* Keywords Data Table */}
        <div className="rounded-xl border border-slate-800 bg-slate-900/40 overflow-hidden">
          {keywords.length === 0 ? (
            <div className="py-16 text-center">
              <Search className="h-10 w-10 text-slate-600 mx-auto mb-3" />
              <h3 className="text-sm font-semibold text-white">No tracked keywords found</h3>
              <p className="mt-1 text-xs text-slate-400 max-w-sm mx-auto">
                {searchQuery || deviceFilter !== 'all'
                  ? 'Try clearing your search query or device filter.'
                  : 'Get started by tracking your highest-intent queries and benchmark them against competitors.'}
              </p>
              {canEdit && (
                <button
                  type="button"
                  onClick={() => setAddModalOpen(true)}
                  className="mt-4 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition cursor-pointer"
                >
                  <Plus className="h-3.5 w-3.5" />
                  <span>Add First Keywords</span>
                </button>
              )}
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs border-collapse">
                <thead>
                  <tr className="border-b border-slate-800 bg-slate-950/50 text-slate-400">
                    <th className="py-3 px-4 font-medium">Keyword & Device</th>
                    <th className="py-3 px-3 font-medium text-center">Coqui Rank</th>
                    <th className="py-3 px-3 font-medium text-center">Delta</th>
                    {overlayProvider !== 'off' && (
                      <>
                        <th className={`py-3 px-3 font-medium text-right whitespace-nowrap ${overlayProvider === 'gsc' ? 'text-blue-300' : 'text-sky-300'}`}>
                          {overlayProvider === 'gsc' ? 'GSC' : 'Bing'} Clicks (28d)
                        </th>
                        <th className={`py-3 px-3 font-medium text-right whitespace-nowrap ${overlayProvider === 'gsc' ? 'text-blue-300' : 'text-sky-300'}`}>
                          {overlayProvider === 'gsc' ? 'GSC' : 'Bing'} Impr. (28d)
                        </th>
                        <th className={`py-3 px-3 font-medium text-right whitespace-nowrap ${overlayProvider === 'gsc' ? 'text-blue-300' : 'text-sky-300'}`}>
                          {overlayProvider === 'gsc' ? 'GSC' : 'Bing'} CTR
                        </th>
                        <th className={`py-3 px-3 font-medium text-center whitespace-nowrap ${overlayProvider === 'gsc' ? 'text-blue-300' : 'text-sky-300'}`}>
                          {overlayProvider === 'gsc' ? 'GSC' : 'Bing'} Pos
                        </th>
                      </>
                    )}
                    <th className="py-3 px-3 font-medium">SERP Features</th>
                    <th className="py-3 px-3 font-medium">Landing Page</th>
                    {competitors.map((comp) => (
                      <th
                        key={comp.id}
                        className="py-3 px-3 font-medium text-center max-w-[120px] truncate"
                        title={comp.domain}
                      >
                        <span className="text-purple-400 font-mono">
                          {comp.label || comp.domain}
                        </span>
                      </th>
                    ))}
                    <th className="py-3 px-3 font-medium">Cadence</th>
                    <th className="py-3 px-3 font-medium">Checked</th>
                    <th className="py-3 px-4 font-medium text-right">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800/60">
                  {keywords.map((kw) => {
                    const compRanks = kw.competitor_ranks || {};

                    return (
                      <tr
                        key={kw.id}
                        className="hover:bg-slate-800/30 transition group text-slate-300"
                      >
                        {/* Keyword & Device */}
                        <td className="py-3 px-4 font-medium text-white">
                          <div className="flex items-center gap-2">
                            <span className="font-medium hover:text-emerald-400 transition cursor-pointer" onClick={() => openHistory(kw)}>
                              {kw.keyword}
                            </span>
                            <span
                              className="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[10px] font-mono bg-slate-800 text-slate-400 border border-slate-700/60"
                              title={`Device: ${kw.device}`}
                            >
                              {kw.device === 'mobile' ? (
                                <Smartphone className="h-2.5 w-2.5" />
                              ) : (
                                <Laptop className="h-2.5 w-2.5" />
                              )}
                              <span>{kw.device}</span>
                            </span>
                          </div>
                        </td>

                        {/* Coqui Rank */}
                        <td className="py-3 px-3 text-center">
                          {kw.current_rank !== null && kw.current_rank !== undefined ? (
                            <span
                              className={`inline-flex items-center justify-center font-mono font-bold text-xs px-2 py-0.5 rounded ${
                                kw.current_rank <= 3
                                  ? 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30'
                                  : kw.current_rank <= 10
                                  ? 'bg-sky-500/15 text-sky-400 border border-sky-500/30'
                                  : 'bg-slate-800 text-slate-300 border border-slate-700'
                              }`}
                            >
                              #{kw.current_rank}
                            </span>
                          ) : (
                            <span className="text-slate-600 font-mono text-[11px]">&gt;100</span>
                          )}
                        </td>

                        {/* Movement Delta */}
                        <td className="py-3 px-3 text-center font-mono text-xs">
                          {kw.delta_type === 'up' && (
                            <span className="inline-flex items-center gap-0.5 text-emerald-400 font-semibold">
                              <ArrowUp className="h-3 w-3" />
                              +{kw.rank_delta}
                            </span>
                          )}
                          {kw.delta_type === 'down' && (
                            <span className="inline-flex items-center gap-0.5 text-rose-400 font-semibold">
                              <ArrowDown className="h-3 w-3" />
                              {kw.rank_delta}
                            </span>
                          )}
                          {kw.delta_type === 'new' && (
                            <span className="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-semibold bg-sky-500/20 text-sky-400 border border-sky-500/30">
                              NEW
                            </span>
                          )}
                          {kw.delta_type === 'dropped' && (
                            <span className="inline-flex items-center px-1.5 py-0.2 rounded text-[10px] font-semibold bg-rose-500/20 text-rose-400 border border-rose-500/30">
                              OUT
                            </span>
                          )}
                          {kw.delta_type === 'unchanged' && (
                            <span className="text-slate-500">
                              <Minus className="h-3 w-3 inline" />
                            </span>
                          )}
                          {kw.delta_type === 'none' && (
                            <span className="text-slate-600">—</span>
                          )}
                        </td>

                        {/* First-Party Search Overlay (Clicks, Impressions, CTR, Avg Position) */}
                        {overlayProvider !== 'off' && (() => {
                          const perf = overlayProvider === 'gsc' ? kw.gsc_performance : kw.bing_performance;
                          const badgeBg = overlayProvider === 'gsc' ? 'bg-blue-500/15 text-blue-300 border-blue-500/30' : 'bg-sky-500/15 text-sky-300 border-sky-500/30';
                          return (
                            <>
                              <td className="py-3 px-3 text-right font-mono font-medium text-slate-200 whitespace-nowrap">
                                {perf?.clicks !== null && perf?.clicks !== undefined ? (
                                  perf.clicks.toLocaleString()
                                ) : (
                                  <span className="text-slate-600">—</span>
                                )}
                              </td>
                              <td className="py-3 px-3 text-right font-mono text-slate-300 whitespace-nowrap">
                                {perf?.impressions !== null && perf?.impressions !== undefined ? (
                                  perf.impressions.toLocaleString()
                                ) : (
                                  <span className="text-slate-600">—</span>
                                )}
                              </td>
                              <td className="py-3 px-3 text-right font-mono text-slate-300 whitespace-nowrap">
                                {perf?.ctr !== null && perf?.ctr !== undefined ? (
                                  `${perf.ctr.toFixed(1)}%`
                                ) : (
                                  <span className="text-slate-600">—</span>
                                )}
                              </td>
                              <td className="py-3 px-3 text-center font-mono whitespace-nowrap">
                                {perf?.position !== null && perf?.position !== undefined ? (
                                  <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-bold border ${badgeBg}`}>
                                    {perf.position.toFixed(1)}
                                  </span>
                                ) : (
                                  <span className="text-slate-600">—</span>
                                )}
                              </td>
                            </>
                          );
                        })()}

                        {/* SERP Features */}
                        <td className="py-3 px-3">
                          <div className="flex items-center gap-1 flex-wrap">
                            {kw.has_ai_overview && (
                              <span
                                className="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-purple-500/15 text-purple-300 border border-purple-500/30"
                                title="Cited in Google AI Overview"
                              >
                                <Sparkles className="h-2.5 w-2.5 text-purple-400" />
                                <span>AIO</span>
                              </span>
                            )}
                            {kw.has_featured_snippet && (
                              <span
                                className="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-500/15 text-amber-300 border border-amber-500/30"
                                title="Owns Featured Snippet (Position 0)"
                              >
                                <Star className="h-2.5 w-2.5 text-amber-400" />
                                <span>Snippet</span>
                              </span>
                            )}
                            {kw.serp_features?.page_features?.map((feat) => {
                              if (feat === 'organic') return null;
                              return (
                                <span
                                  key={feat}
                                  className="inline-flex items-center px-1 py-0.5 rounded text-[9px] font-mono bg-slate-800/80 text-slate-400 border border-slate-700/50"
                                >
                                  {feat.replace('_', ' ')}
                                </span>
                              );
                            })}
                            {!kw.has_ai_overview &&
                              !kw.has_featured_snippet &&
                              (!kw.serp_features?.page_features ||
                                kw.serp_features.page_features.length === 0) && (
                                <span className="text-slate-600 text-[11px]">—</span>
                              )}
                          </div>
                        </td>

                        {/* Landing Page */}
                        <td className="py-3 px-3 max-w-[200px]">
                          {kw.ranking_url ? (
                            <a
                              href={kw.ranking_url}
                              target="_blank"
                              rel="noreferrer"
                              className="text-slate-300 hover:text-white transition flex items-center gap-1 truncate font-mono text-[11px]"
                              title={kw.ranking_url}
                            >
                              <span className="truncate">
                                {kw.ranking_url.replace(/^https?:\/\/[^/]+/i, '') || '/'}
                              </span>
                              <ExternalLink className="h-2.5 w-2.5 shrink-0 text-slate-500" />
                            </a>
                          ) : (
                            <span className="text-slate-600 text-[11px]">—</span>
                          )}
                        </td>

                        {/* Competitor Columns */}
                        {competitors.map((comp) => {
                          const cData = compRanks[comp.domain];
                          const cRank = cData?.rank;

                          return (
                            <td key={comp.id} className="py-3 px-3 text-center font-mono">
                              {cRank !== null && cRank !== undefined ? (
                                <div className="inline-flex items-center gap-1">
                                  <span
                                    className={`px-1.5 py-0.5 rounded text-[11px] font-medium ${
                                      kw.current_rank && cRank < kw.current_rank
                                        ? 'text-rose-300 bg-rose-500/10'
                                        : kw.current_rank && cRank > kw.current_rank
                                        ? 'text-emerald-300 bg-emerald-500/10'
                                        : 'text-slate-300 bg-slate-800'
                                    }`}
                                  >
                                    #{cRank}
                                  </span>
                                  {cData?.has_ai_overview && (
                                    <span title="Competitor cited in AIO">
                                      <Sparkles className="h-2.5 w-2.5 text-purple-400" />
                                    </span>
                                  )}
                                </div>
                              ) : (
                                <span className="text-slate-600 text-[11px]">&gt;100</span>
                              )}
                            </td>
                          );
                        })}

                        {/* Cadence */}
                        <td className="py-3 px-3 font-mono text-[11px] text-slate-400 capitalize">
                          {kw.cadence}
                        </td>

                        {/* Checked */}
                        <td className="py-3 px-3 text-slate-400 text-[11px] whitespace-nowrap">
                          {formatRelativeTime(kw.last_checked_at)}
                        </td>

                        {/* Actions */}
                        <td className="py-3 px-4 text-right">
                          <div className="flex items-center justify-end gap-1">
                            <FeatureLink
                              feature="content-briefs"
                              pill={false}
                              href={`/workspaces/${workspace.id}/projects/${project.id}/keywords/brief?keyword=${encodeURIComponent(kw.keyword)}`}
                              title="Generate SERP Content Brief"
                              className="p-1 rounded hover:bg-slate-800 text-slate-400 hover:text-teal-400 transition cursor-pointer"
                            >
                              <FileText className="h-3.5 w-3.5" />
                            </FeatureLink>
                            <button
                              type="button"
                              onClick={() => openHistory(kw)}
                              title="View SERP History"
                              className="p-1 rounded hover:bg-slate-800 text-slate-400 hover:text-white transition cursor-pointer"
                            >
                              <History className="h-3.5 w-3.5" />
                            </button>
                            {canEdit && (
                              <button
                                type="button"
                                onClick={() => handleDeleteKeyword(kw)}
                                title="Remove Keyword"
                                className="p-1 rounded hover:bg-rose-500/20 text-slate-400 hover:text-rose-400 transition cursor-pointer"
                              >
                                <Trash2 className="h-3.5 w-3.5" />
                              </button>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>

      {/* Track Keywords Modal */}
      {addModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-sm p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <h2 className="text-base font-semibold text-white">Track New Keywords</h2>
              <button
                type="button"
                onClick={() => setAddModalOpen(false)}
                className="text-slate-400 hover:text-white"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            <form onSubmit={handleAddSubmit} className="space-y-4">
              <div>
                <label className="block text-xs font-medium text-slate-300 mb-1">
                  Keywords (one per line, up to 100)
                </label>
                <textarea
                  value={addForm.data.keywords}
                  onChange={(e) => addForm.setData('keywords', e.target.value)}
                  rows={6}
                  placeholder={`seo agency software\nrank tracker api\nserp intelligence`}
                  className="w-full rounded-lg bg-slate-950 border border-slate-800 px-3 py-2 text-xs font-mono text-white placeholder-slate-600 focus:outline-none focus:border-emerald-500"
                  required
                />
                {addForm.errors.keywords && (
                  <p className="mt-1 text-xs text-rose-400">{addForm.errors.keywords}</p>
                )}
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-slate-300 mb-1">Device</label>
                  <select
                    value={addForm.data.device}
                    onChange={(e) => addForm.setData('device', e.target.value)}
                    className="w-full rounded-lg bg-slate-950 border border-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-emerald-500"
                  >
                    <option value="desktop">Desktop</option>
                    <option value="mobile">Mobile</option>
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-medium text-slate-300 mb-1">Cadence</label>
                  <select
                    value={addForm.data.cadence}
                    onChange={(e) => addForm.setData('cadence', e.target.value)}
                    className="w-full rounded-lg bg-slate-950 border border-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-emerald-500"
                  >
                    <option value="weekly">Weekly</option>
                    <option value="daily">Daily</option>
                  </select>
                </div>
              </div>

              <div className="rounded-lg bg-slate-950/60 border border-slate-800/80 p-3 text-[11px] text-slate-400 space-y-1">
                <p>
                  • Location: <span className="font-mono text-slate-200">LOC:{project.location_code}</span> ({project.language_code.toUpperCase()})
                </p>
                <p>
                  • Positions for <span className="font-mono text-slate-200">{project.domain}</span> and all {competitors.length} configured competitors are parsed simultaneously at $0 extra API spend.
                </p>
              </div>

              <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-800">
                <button
                  type="button"
                  onClick={() => setAddModalOpen(false)}
                  className="px-3.5 py-1.5 rounded-lg border border-slate-700 bg-slate-800 hover:bg-slate-700 text-xs font-medium text-slate-300 transition"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={addForm.processing}
                  className="px-4 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition disabled:opacity-50"
                >
                  {addForm.processing ? 'Adding...' : 'Add Keywords'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* SERP History Modal */}
      {historyModalKeyword && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-sm p-4">
          <div className="bg-slate-900 border border-slate-800 rounded-2xl max-w-2xl w-full p-6 shadow-2xl space-y-4">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <div>
                <h2 className="text-base font-semibold text-white">
                  SERP History: <span className="text-emerald-400">{historyModalKeyword.keyword}</span>
                </h2>
                <p className="text-xs text-slate-400 font-mono">
                  Device: {historyModalKeyword.device} • Location: {historyModalKeyword.location_code}
                </p>
              </div>
              <button
                type="button"
                onClick={() => setHistoryModalKeyword(null)}
                className="text-slate-400 hover:text-white"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            {historyLoading ? (
              <div className="py-12 text-center text-xs text-slate-400">
                <RefreshCw className="h-6 w-6 animate-spin mx-auto mb-2 text-slate-500" />
                Loading historical snapshots...
              </div>
            ) : historySnapshots.length === 0 ? (
              <div className="py-10 text-center text-xs text-slate-500">
                No historical snapshots recorded yet for this keyword.
              </div>
            ) : (
              <div className="max-h-96 overflow-y-auto space-y-2 pr-1">
                <table className="w-full text-left text-xs border-collapse">
                  <thead>
                    <tr className="border-b border-slate-800 text-slate-400 bg-slate-950/40">
                      <th className="py-2 px-3">Date</th>
                      <th className="py-2 px-3">Domain</th>
                      <th className="py-2 px-3 text-center">Rank</th>
                      <th className="py-2 px-3">SERP Features</th>
                      <th className="py-2 px-3">URL</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/50">
                    {historySnapshots.map((snap) => (
                      <tr key={snap.id} className="hover:bg-slate-800/30">
                        <td className="py-2 px-3 font-mono text-[11px] text-slate-400">
                          {snap.checked_on}
                        </td>
                        <td className="py-2 px-3 font-mono text-slate-200">
                          {snap.domain === project.domain ? (
                            <span className="text-emerald-400 font-semibold">{snap.domain}</span>
                          ) : (
                            <span className="text-purple-400">{snap.domain}</span>
                          )}
                        </td>
                        <td className="py-2 px-3 text-center font-mono">
                          {snap.rank_group !== null && snap.rank_group !== undefined ? (
                            <span className="font-bold text-white">#{snap.rank_group}</span>
                          ) : (
                            <span className="text-slate-600">&gt;100</span>
                          )}
                        </td>
                        <td className="py-2 px-3">
                          <div className="flex items-center gap-1">
                            {snap.has_ai_overview && (
                              <span className="inline-flex items-center gap-0.5 px-1 py-0.2 rounded text-[9px] bg-purple-500/20 text-purple-300">
                                <Sparkles className="h-2 w-2" /> AIO
                              </span>
                            )}
                            {snap.has_featured_snippet && (
                              <span className="inline-flex items-center gap-0.5 px-1 py-0.2 rounded text-[9px] bg-amber-500/20 text-amber-300">
                                <Star className="h-2 w-2" /> Snippet
                              </span>
                            )}
                            {!snap.has_ai_overview && !snap.has_featured_snippet && (
                              <span className="text-slate-600 text-[10px]">—</span>
                            )}
                          </div>
                        </td>
                        <td className="py-2 px-3 max-w-[150px] truncate text-[11px] font-mono text-slate-400">
                          {snap.ranking_url ? (
                            <a
                              href={snap.ranking_url}
                              target="_blank"
                              rel="noreferrer"
                              className="hover:text-white truncate"
                            >
                              {snap.ranking_url}
                            </a>
                          ) : (
                            '—'
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            <div className="flex justify-end pt-2 border-t border-slate-800">
              <button
                type="button"
                onClick={() => setHistoryModalKeyword(null)}
                className="px-3 py-1.5 rounded-lg border border-slate-700 bg-slate-800 hover:bg-slate-700 text-xs text-slate-300 transition"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
