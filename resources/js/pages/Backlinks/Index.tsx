import { Head, Link, router } from '@inertiajs/react';
import {
  Activity,
  AlertTriangle,
  ArrowDown,
  ArrowLeft,
  ArrowUp,
  BarChart3,
  Calendar,
  CheckCircle2,
  Copy,
  ExternalLink,
  Filter,
  Globe,
  History,
  Layers,
  Link2,
  RefreshCw,
  Search,
  Shield,
  ShieldAlert,
  Sparkles,
  TrendingDown,
  TrendingUp,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface ProjectDetails {
  id: string;
  name: string;
  domain: string;
  canonicalUrl: string;
}

interface BacklinkSummary {
  rank: number;
  backlinks: number;
  referring_domains: number;
  referring_main_domains: number;
  referring_pages: number;
  dofollow: number;
  nofollow: number;
  dofollow_ratio: number;
  cached: boolean;
  fetched_at?: string | null;
}

interface ReferringDomainItem {
  domain: string;
  rank: number;
  backlinks: number;
  dofollow: number;
  nofollow: number;
  dofollow_ratio: number;
  spam_score: number;
  first_seen?: string | null;
  last_seen?: string | null;
}

interface AnchorItem {
  anchor: string;
  backlinks: number;
  referring_domains: number;
  dofollow: number;
  nofollow: number;
  dofollow_ratio: number;
}

interface TopPageItem {
  url: string;
  path: string;
  rank: number;
  backlinks: number;
  referring_domains: number;
  dofollow: number;
  nofollow: number;
  crawl_status?: number | null;
  is_broken: boolean;
  redirect_priority?: string | null;
}

interface BrokenPageItem {
  url: string;
  path: string;
  rank: number;
  backlinks: number;
  referring_domains: number;
  crawl_status: number;
  redirect_priority: string;
}

interface CrawlRunSummary {
  id: string;
  status: string;
  finished_at?: string | null;
  total_urls: number;
}

interface TimelineItem {
  date: string;
  backlinks: number;
  referring_domains: number;
  new_backlinks: number;
  lost_backlinks: number;
  new_referring_domains: number;
  lost_referring_domains: number;
  net_domains_delta: number;
  net_backlinks_delta: number;
  rank: number;
}

interface NewLostDomainItem extends ReferringDomainItem {
  event_type: 'new' | 'lost';
  event_date: string;
}

interface VelocitySummary {
  days: number;
  total_new: number;
  total_lost: number;
  net_velocity: number;
  trend: 'growing' | 'declining' | 'stable';
}

interface BacklinkAlert {
  type: string;
  severity: string;
  message: string;
  detected_at: string;
  meta: Record<string, any>;
}

interface BacklinksPageProps {
  workspace: WorkspaceSummary;
  project: ProjectDetails;
  summary: BacklinkSummary;
  referringDomains: ReferringDomainItem[];
  anchors: AnchorItem[];
  topPages: TopPageItem[];
  brokenPages: BrokenPageItem[];
  crawlRun?: CrawlRunSummary | null;
  timeline?: TimelineItem[];
  newDomains?: NewLostDomainItem[];
  lostDomains?: NewLostDomainItem[];
  velocity?: VelocitySummary;
  alerts?: BacklinkAlert[];
  selectedDays?: number;
}

export default function BacklinksIndex({
  workspace,
  project,
  summary,
  referringDomains = [],
  anchors = [],
  topPages = [],
  brokenPages = [],
  crawlRun,
  timeline = [],
  newDomains = [],
  lostDomains = [],
  velocity,
  alerts = [],
  selectedDays = 90,
}: BacklinksPageProps) {
  const [activeTab, setActiveTab] = useState<'domains' | 'timeline' | 'new_lost' | 'pages' | 'anchors' | 'broken'>('domains');
  const [daysWindow, setDaysWindow] = useState<number>(selectedDays);
  const [domainSearch, setDomainSearch] = useState('');
  const [newLostSearch, setNewLostSearch] = useState('');
  const [newLostFilter, setNewLostFilter] = useState<'all' | 'new' | 'lost'>('all');
  const [pageSearch, setPageSearch] = useState('');
  const [anchorSearch, setAnchorSearch] = useState('');
  const [pageStatusFilter, setPageStatusFilter] = useState<'all' | 'broken' | 'ok'>('all');
  const [refreshing, setRefreshing] = useState(false);
  const [copiedUrl, setCopiedUrl] = useState<string | null>(null);

  // Sorting for Referring Domains
  const [domainSortField, setDomainSortField] = useState<'rank' | 'backlinks' | 'dofollow_ratio' | 'spam_score'>('rank');
  const [domainSortAsc, setDomainSortAsc] = useState(false);

  const handleRefresh = () => {
    if (confirm(`Refresh backlink intelligence for ${project.domain} from DataForSEO?`)) {
      setRefreshing(true);
      router.post(
        `/workspaces/${workspace.id}/projects/${project.id}/backlinks/refresh`,
        { days: daysWindow },
        {
          onFinish: () => setRefreshing(false),
        }
      );
    }
  };

  const handleDaysChange = (days: number) => {
    setDaysWindow(days);
    router.get(
      `/workspaces/${workspace.id}/projects/${project.id}/backlinks`,
      { days },
      { preserveState: true, preserveScroll: true }
    );
  };

  const handleCopy = (url: string) => {
    navigator.clipboard.writeText(url);
    setCopiedUrl(url);
    setTimeout(() => setCopiedUrl(null), 2000);
  };

  const filteredDomains = useMemo(() => {
    const q = domainSearch.toLowerCase().trim();
    let list = referringDomains;
    if (q !== '') {
      list = list.filter((d) => d.domain.toLowerCase().includes(q));
    }

    return [...list].sort((a, b) => {
      const valA = a[domainSortField] ?? 0;
      const valB = b[domainSortField] ?? 0;
      return domainSortAsc ? (valA > valB ? 1 : -1) : (valA < valB ? 1 : -1);
    });
  }, [referringDomains, domainSearch, domainSortField, domainSortAsc]);

  const filteredPages = useMemo(() => {
    const q = pageSearch.toLowerCase().trim();
    let list = topPages;
    if (q !== '') {
      list = list.filter((p) => p.url.toLowerCase().includes(q) || p.path.toLowerCase().includes(q));
    }
    if (pageStatusFilter === 'broken') {
      list = list.filter((p) => p.is_broken);
    } else if (pageStatusFilter === 'ok') {
      list = list.filter((p) => !p.is_broken);
    }
    return list;
  }, [topPages, pageSearch, pageStatusFilter]);

  const filteredAnchors = useMemo(() => {
    const q = anchorSearch.toLowerCase().trim();
    if (q === '') return anchors;
    return anchors.filter((a) => a.anchor.toLowerCase().includes(q));
  }, [anchors, anchorSearch]);

  const combinedNewLost = useMemo(() => {
    const list = [...newDomains, ...lostDomains];
    const q = newLostSearch.toLowerCase().trim();
    return list.filter((item) => {
      if (newLostFilter !== 'all' && item.event_type !== newLostFilter) {
        return false;
      }
      if (q !== '' && !item.domain.toLowerCase().includes(q)) {
        return false;
      }
      return true;
    }).sort((a, b) => new Date(b.event_date).getTime() - new Date(a.event_date).getTime());
  }, [newDomains, lostDomains, newLostFilter, newLostSearch]);

  const toggleSort = (field: 'rank' | 'backlinks' | 'dofollow_ratio' | 'spam_score') => {
    if (domainSortField === field) {
      setDomainSortAsc(!domainSortAsc);
    } else {
      setDomainSortField(field);
      setDomainSortAsc(false);
    }
  };

  return (
    <AppLayout currentWorkspace={workspace}>
      <Head title={`${project.name} - Backlink Profile & Referring Domains`} />

      <div className="space-y-6">
        {/* Navigation Breadcrumb */}
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
          <span className="text-white font-medium">Backlink Intelligence</span>
        </div>

        {/* Page Header */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-slate-800 pb-6">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
                <Link2 className="h-6 w-6 text-purple-400" />
                <span>Backlink Profile & Referring Domains</span>
              </h1>
              <a
                href={project.canonicalUrl}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-1 rounded bg-slate-800 border border-slate-700 px-2.5 py-0.5 text-xs text-slate-300 hover:text-white transition"
              >
                <span className="font-mono">{project.domain}</span>
                <ExternalLink className="h-3 w-3 text-slate-400" />
              </a>
              {summary.cached && (
                <span className="rounded-full bg-emerald-500/10 border border-emerald-500/20 px-2.5 py-0.5 text-xs font-mono text-emerald-400">
                  14-Day Cache Active ($0)
                </span>
              )}
            </div>
            <p className="mt-1 text-xs text-slate-400">
              Authority metrics, link attribute distributions, and high-equity 404 recovery opportunities joined from local crawl audits for{' '}
              <span className="text-slate-200 font-mono font-medium">{project.domain}</span>.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={handleRefresh}
              disabled={refreshing}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer disabled:opacity-50"
              title="Force refresh backlink intelligence via live DataForSEO API"
            >
              <RefreshCw className={`h-3.5 w-3.5 text-purple-400 ${refreshing ? 'animate-spin' : ''}`} />
              <span>{refreshing ? 'Refreshing...' : 'Refresh Backlinks'}</span>
            </button>

            <Link
              href={`/workspaces/${workspace.id}/projects/${project.id}/backlinks/intersect`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-sky-500/10 hover:bg-sky-500/20 border border-sky-500/30 text-sky-300 text-xs font-medium transition cursor-pointer"
            >
              <Sparkles className="h-3.5 w-3.5 text-sky-400" />
              <span>Link Intersect</span>
            </Link>

            <Link
              href={`/workspaces/${workspace.id}/projects/${project.id}/backlinks/watchlist`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-purple-500/10 hover:bg-purple-500/20 border border-purple-500/30 text-purple-300 text-xs font-medium transition cursor-pointer"
            >
              <ShieldAlert className="h-3.5 w-3.5 text-purple-400" />
              <span>Active Watchlist</span>
            </Link>

            <Link
              href={`/workspaces/${workspace.id}/projects/${project.id}`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer"
            >
              <ArrowLeft className="h-3.5 w-3.5 text-slate-400" />
              <span>Project Overview</span>
            </Link>
          </div>
        </div>

        {/* Dynamic Backlink Drop and Spam Alerts */}
        {alerts && alerts.length > 0 && (
          <div className="space-y-3">
            {alerts.map((alert, idx) => (
              <div
                key={idx}
                className={`rounded-xl border p-4 flex items-start gap-3 ${
                  alert.severity === 'critical'
                    ? 'border-rose-500/50 bg-rose-950/30 text-rose-200'
                    : 'border-amber-500/50 bg-amber-950/30 text-amber-200'
                }`}
              >
                <div className={`rounded-lg p-2 shrink-0 ${
                  alert.severity === 'critical' ? 'bg-rose-500/20 text-rose-400' : 'bg-amber-500/20 text-amber-400'
                }`}>
                  <ShieldAlert className="h-5 w-5" />
                </div>
                <div className="flex-1">
                  <div className="flex items-center gap-2">
                    <h4 className="text-sm font-semibold">
                      {alert.type === 'backlink_drop' ? 'Sudden Backlink Drop Alert' : 'Toxic Link Spam Spike Alert'}
                    </h4>
                    <span className={`text-[10px] uppercase font-mono px-1.5 py-0.5 rounded border font-bold ${
                      alert.severity === 'critical'
                        ? 'bg-rose-500/20 text-rose-300 border-rose-500/40'
                        : 'bg-amber-500/20 text-amber-300 border-amber-500/40'
                    }`}>
                      {alert.severity}
                    </span>
                  </div>
                  <p className="mt-1 text-xs opacity-90">{alert.message}</p>
                </div>
              </div>
            ))}
          </div>
        )}

        {/* High-Authority 404 Pages Alert Banner */}
        {brokenPages.length > 0 && (
          <div className="rounded-xl border border-rose-500/40 bg-rose-950/20 p-4">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <div className="flex items-start gap-3">
                <div className="rounded-lg bg-rose-500/20 p-2 text-rose-400 shrink-0">
                  <ShieldAlert className="h-5 w-5" />
                </div>
                <div>
                  <h3 className="text-sm font-semibold text-rose-300 flex items-center gap-2">
                    <span>{brokenPages.length} High-Authority Broken Page(s) Detected</span>
                    <span className="text-[10px] uppercase font-mono px-1.5 py-0.5 rounded bg-rose-500/20 text-rose-400 border border-rose-500/30">
                      Immediate 301 Needed
                    </span>
                  </h3>
                  <p className="mt-1 text-xs text-rose-300/80">
                    These target URLs receive inbound backlinks from external referring domains, but returned 404 in your latest crawl audit
                    {crawlRun?.finished_at ? ` (${new Date(crawlRun.finished_at).toLocaleDateString()})` : ''}.
                    Set up 301 redirects to recover lost link equity and prevent domain authority leakage.
                  </p>
                </div>
              </div>
              <button
                type="button"
                onClick={() => setActiveTab('broken')}
                className="shrink-0 px-3.5 py-1.5 rounded-lg bg-rose-500 hover:bg-rose-400 text-slate-950 text-xs font-semibold transition cursor-pointer"
              >
                View Broken Targets ({brokenPages.length})
              </button>
            </div>
          </div>
        )}

        {/* Core KPI Metrics Grid */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          {/* Domain Authority Rank */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4 relative overflow-hidden">
            <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
              <Shield className="h-3.5 w-3.5 text-purple-400" />
              <span>Domain Authority Rank</span>
            </span>
            <div className="mt-2 flex items-baseline gap-2">
              <span className="text-2xl font-bold font-mono text-white">
                {summary.rank > 0 ? summary.rank : '—'}
              </span>
              <span className="text-xs text-slate-500 font-mono">/ 1,000</span>
            </div>
            <div className="mt-2 flex items-center gap-1.5">
              <div className="flex-1 bg-slate-800 h-1.5 rounded-full overflow-hidden">
                <div
                  className="bg-purple-500 h-full rounded-full"
                  style={{ width: `${Math.min(100, Math.max(5, (summary.rank / 1000) * 100))}%` }}
                />
              </div>
              <span className="text-[10px] text-purple-400 font-mono font-medium">
                {summary.rank > 500 ? 'High' : summary.rank > 200 ? 'Moderate' : 'Building'}
              </span>
            </div>
          </div>

          {/* Total Backlinks */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
              <Link2 className="h-3.5 w-3.5 text-sky-400" />
              <span>Total Backlinks</span>
            </span>
            <div className="mt-2 text-2xl font-bold font-mono text-white">
              {summary.backlinks.toLocaleString()}
            </div>
            <div className="mt-2 flex items-center justify-between text-[11px] text-slate-400">
              <span className="text-emerald-400">{summary.dofollow_ratio}% Dofollow</span>
              <span className="text-slate-500 font-mono">
                {summary.dofollow.toLocaleString()} dofollow / {summary.nofollow.toLocaleString()} nofollow
              </span>
            </div>
          </div>

          {/* Referring Domains */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
              <Globe className="h-3.5 w-3.5 text-emerald-400" />
              <span>Referring Domains</span>
            </span>
            <div className="mt-2 text-2xl font-bold font-mono text-emerald-400">
              {summary.referring_domains.toLocaleString()}
            </div>
            <div className="mt-2 flex items-center justify-between text-[11px] text-slate-400">
              <span>Main Root Domains</span>
              <span className="font-mono text-slate-200">
                {summary.referring_main_domains.toLocaleString()}
              </span>
            </div>
          </div>

          {/* Crawl Intersect 404s */}
          <div className={`rounded-xl border p-4 ${
            brokenPages.length > 0
              ? 'border-rose-500/40 bg-rose-950/10'
              : 'border-slate-800 bg-slate-900/40'
          }`}>
            <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
              <AlertTriangle className={`h-3.5 w-3.5 ${brokenPages.length > 0 ? 'text-rose-400' : 'text-slate-400'}`} />
              <span>Broken 404 Targets</span>
            </span>
            <div className={`mt-2 text-2xl font-bold font-mono ${
              brokenPages.length > 0 ? 'text-rose-400' : 'text-slate-300'
            }`}>
              {brokenPages.length}
            </div>
            <div className="mt-2 text-[11px] text-slate-400">
              {brokenPages.length > 0
                ? `${brokenPages.reduce((acc, p) => acc + p.backlinks, 0)} total backlinks at risk`
                : 'All top linked pages reachable'}
            </div>
          </div>
        </div>

        {/* View Navigation Tabs & Window Selector */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3 border-b border-slate-800 pb-3">
          <div className="flex items-center gap-2 flex-wrap">
            <button
              type="button"
              onClick={() => setActiveTab('domains')}
              className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition cursor-pointer ${
                activeTab === 'domains'
                  ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30'
                  : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50'
              }`}
            >
              <Globe className="h-3.5 w-3.5" />
              <span>Referring Domains ({referringDomains.length})</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('timeline')}
              className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition cursor-pointer ${
                activeTab === 'timeline'
                  ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30'
                  : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50'
              }`}
            >
              <History className="h-3.5 w-3.5" />
              <span>Timeline & Velocity ({timeline.length})</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('new_lost')}
              className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition cursor-pointer ${
                activeTab === 'new_lost'
                  ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30'
                  : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50'
              }`}
            >
              <Activity className="h-3.5 w-3.5" />
              <span>New & Lost ({newDomains.length + lostDomains.length})</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('pages')}
              className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition cursor-pointer ${
                activeTab === 'pages'
                  ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30'
                  : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50'
              }`}
            >
              <Layers className="h-3.5 w-3.5" />
              <span>Top Linked Pages ({topPages.length})</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('anchors')}
              className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition cursor-pointer ${
                activeTab === 'anchors'
                  ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30'
                  : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50'
              }`}
            >
              <BarChart3 className="h-3.5 w-3.5" />
              <span>Anchor Texts ({anchors.length})</span>
            </button>

            {brokenPages.length > 0 && (
              <button
                type="button"
                onClick={() => setActiveTab('broken')}
                className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition cursor-pointer ${
                  activeTab === 'broken'
                    ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30'
                    : 'text-rose-400 hover:text-rose-200 hover:bg-rose-500/10'
                }`}
              >
                <AlertTriangle className="h-3.5 w-3.5" />
                <span>404 Opportunities ({brokenPages.length})</span>
              </button>
            )}
          </div>

          {/* Window Selector */}
          <div className="flex items-center gap-1.5 bg-slate-900 border border-slate-800 rounded-lg p-1 text-xs shrink-0">
            <span className="text-slate-400 text-[11px] px-2 flex items-center gap-1">
              <Calendar className="h-3 w-3 text-slate-500" />
              <span>Window:</span>
            </span>
            {[7, 30, 90].map((d) => (
              <button
                key={d}
                type="button"
                onClick={() => handleDaysChange(d)}
                className={`px-2.5 py-1 rounded text-xs font-mono font-medium transition cursor-pointer ${
                  daysWindow === d
                    ? 'bg-purple-600 text-white'
                    : 'text-slate-400 hover:text-white hover:bg-slate-800'
                }`}
              >
                {d}d
              </button>
            ))}
          </div>
        </div>

        {/* TAB 1: Referring Domains Explorer */}
        {activeTab === 'domains' && (
          <div className="space-y-3">
            {/* Toolbar */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-slate-900/40 border border-slate-800 p-3 rounded-xl">
              <div className="relative flex-1 max-w-sm">
                <input
                  type="text"
                  value={domainSearch}
                  onChange={(e) => setDomainSearch(e.target.value)}
                  placeholder="Filter referring domains..."
                  className="w-full rounded-lg bg-slate-950 border border-slate-800 pl-8 pr-3 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
                />
                <Search className="h-3.5 w-3.5 text-slate-500 absolute left-2.5 top-2 pointer-events-none" />
              </div>

              <div className="flex items-center gap-2 text-xs text-slate-400">
                <span>Showing {filteredDomains.length} of {referringDomains.length} domains</span>
              </div>
            </div>

            {/* Domains Table */}
            <div className="rounded-xl border border-slate-800 bg-slate-900/40 overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-950/60 border-b border-slate-800 text-slate-400 uppercase font-mono text-[10px] tracking-wider">
                    <tr>
                      <th className="py-3 px-4">Referring Domain</th>
                      <th
                        className="py-3 px-3 cursor-pointer hover:text-white transition"
                        onClick={() => toggleSort('rank')}
                      >
                        <div className="flex items-center gap-1">
                          <span>Domain Rank</span>
                          {domainSortField === 'rank' && (
                            domainSortAsc ? <ArrowUp className="h-3 w-3" /> : <ArrowDown className="h-3 w-3" />
                          )}
                        </div>
                      </th>
                      <th
                        className="py-3 px-3 text-right cursor-pointer hover:text-white transition"
                        onClick={() => toggleSort('backlinks')}
                      >
                        <div className="flex items-center justify-end gap-1">
                          <span>Backlinks</span>
                          {domainSortField === 'backlinks' && (
                            domainSortAsc ? <ArrowUp className="h-3 w-3" /> : <ArrowDown className="h-3 w-3" />
                          )}
                        </div>
                      </th>
                      <th
                        className="py-3 px-3 text-center cursor-pointer hover:text-white transition"
                        onClick={() => toggleSort('dofollow_ratio')}
                      >
                        <div className="flex items-center justify-center gap-1">
                          <span>Dofollow %</span>
                          {domainSortField === 'dofollow_ratio' && (
                            domainSortAsc ? <ArrowUp className="h-3 w-3" /> : <ArrowDown className="h-3 w-3" />
                          )}
                        </div>
                      </th>
                      <th
                        className="py-3 px-3 text-center cursor-pointer hover:text-white transition"
                        onClick={() => toggleSort('spam_score')}
                      >
                        <div className="flex items-center justify-center gap-1">
                          <span>Spam Score</span>
                          {domainSortField === 'spam_score' && (
                            domainSortAsc ? <ArrowUp className="h-3 w-3" /> : <ArrowDown className="h-3 w-3" />
                          )}
                        </div>
                      </th>
                      <th className="py-3 px-4 text-right">Discovered</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60 font-sans">
                    {filteredDomains.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="py-8 text-center text-slate-500 text-xs">
                          No referring domains matched your search criteria.
                        </td>
                      </tr>
                    ) : (
                      filteredDomains.map((d) => (
                        <tr key={d.domain} className="hover:bg-slate-800/30 transition text-slate-300">
                          {/* Domain */}
                          <td className="py-3 px-4 font-medium text-white">
                            <div className="flex items-center gap-2">
                              <Globe className="h-3.5 w-3.5 text-purple-400 shrink-0" />
                              <a
                                href={`https://${d.domain}`}
                                target="_blank"
                                rel="noreferrer"
                                className="hover:text-purple-400 transition flex items-center gap-1"
                              >
                                <span>{d.domain}</span>
                                <ExternalLink className="h-2.5 w-2.5 opacity-60" />
                              </a>
                            </div>
                          </td>

                          {/* Domain Rank */}
                          <td className="py-3 px-3">
                            <div className="inline-flex items-center gap-1.5">
                              <span className="font-mono font-bold text-white">{d.rank}</span>
                              <div className="w-12 bg-slate-800 h-1.5 rounded-full overflow-hidden">
                                <div
                                  className="bg-purple-500 h-full rounded-full"
                                  style={{ width: `${Math.min(100, Math.max(10, (d.rank / 1000) * 100))}%` }}
                                />
                              </div>
                            </div>
                          </td>

                          {/* Backlinks */}
                          <td className="py-3 px-3 text-right font-mono font-semibold text-white">
                            {d.backlinks.toLocaleString()}
                          </td>

                          {/* Dofollow % */}
                          <td className="py-3 px-3 text-center">
                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-mono bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                              {d.dofollow_ratio}%
                            </span>
                          </td>

                          {/* Spam Score */}
                          <td className="py-3 px-3 text-center">
                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono border ${
                              d.spam_score > 60
                                ? 'bg-rose-500/10 text-rose-400 border-rose-500/20'
                                : d.spam_score > 30
                                ? 'bg-amber-500/10 text-amber-400 border-amber-500/20'
                                : 'bg-slate-800 text-slate-400 border-slate-700/60'
                            }`}>
                              {d.spam_score > 0 ? `${d.spam_score}%` : 'Low'}
                            </span>
                          </td>

                          {/* Discovered / First Seen */}
                          <td className="py-3 px-4 text-right text-[11px] text-slate-500 font-mono">
                            {d.first_seen ? new Date(d.first_seen).toLocaleDateString() : '—'}
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* TAB: Timeline & Velocity */}
        {activeTab === 'timeline' && (
          <div className="space-y-4">
            {/* Velocity Summary Cards */}
            <div className="grid grid-cols-1 sm:grid-cols-4 gap-4">
              <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
                <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
                  <Activity className="h-3.5 w-3.5 text-purple-400" />
                  <span>Net Domain Velocity ({daysWindow}d)</span>
                </span>
                <div className="mt-2 flex items-center gap-2">
                  <span className={`text-2xl font-bold font-mono ${
                    (velocity?.net_velocity ?? 0) > 0 ? 'text-emerald-400' : (velocity?.net_velocity ?? 0) < 0 ? 'text-rose-400' : 'text-slate-300'
                  }`}>
                    {(velocity?.net_velocity ?? 0) > 0 ? `+${velocity?.net_velocity}` : velocity?.net_velocity ?? 0}
                  </span>
                  {(velocity?.net_velocity ?? 0) > 0 ? (
                    <TrendingUp className="h-5 w-5 text-emerald-400" />
                  ) : (velocity?.net_velocity ?? 0) < 0 ? (
                    <TrendingDown className="h-5 w-5 text-rose-400" />
                  ) : null}
                </div>
                <div className="mt-1 text-[11px] text-slate-400">
                  Trend: <span className="font-semibold capitalize text-slate-200">{velocity?.trend ?? 'stable'}</span>
                </div>
              </div>

              <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
                <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
                  <ArrowUp className="h-3.5 w-3.5 text-emerald-400" />
                  <span>New Acquired Domains</span>
                </span>
                <div className="mt-2 text-2xl font-bold font-mono text-emerald-400">
                  +{velocity?.total_new ?? newDomains.length}
                </div>
                <div className="mt-1 text-[11px] text-slate-400">
                  First seen in last {daysWindow} days
                </div>
              </div>

              <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
                <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
                  <ArrowDown className="h-3.5 w-3.5 text-rose-400" />
                  <span>Lost Referring Domains</span>
                </span>
                <div className="mt-2 text-2xl font-bold font-mono text-rose-400">
                  -{velocity?.total_lost ?? lostDomains.length}
                </div>
                <div className="mt-1 text-[11px] text-slate-400">
                  Not seen in last {daysWindow} days
                </div>
              </div>

              <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
                <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
                  <History className="h-3.5 w-3.5 text-sky-400" />
                  <span>Snapshots Recorded</span>
                </span>
                <div className="mt-2 text-2xl font-bold font-mono text-white">
                  {timeline.length}
                </div>
                <div className="mt-1 text-[11px] text-slate-400">
                  Historical sync points in DB
                </div>
              </div>
            </div>

            {/* Timeseries Velocity Table */}
            <div className="rounded-xl border border-slate-800 bg-slate-900/40 overflow-hidden">
              <div className="p-4 border-b border-slate-800 flex items-center justify-between">
                <div>
                  <h3 className="text-sm font-semibold text-white">Backlink & Referring Domain Timeseries</h3>
                  <p className="text-xs text-slate-400 mt-0.5">
                    Daily historical snapshots tracked for {project.domain} across the selected {daysWindow}-day window.
                  </p>
                </div>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-950/60 border-b border-slate-800 text-slate-400">
                    <tr>
                      <th className="py-2.5 px-4 font-medium">Snapshot Date</th>
                      <th className="py-2.5 px-3 text-right font-medium">Total Backlinks</th>
                      <th className="py-2.5 px-3 text-right font-medium">New Links</th>
                      <th className="py-2.5 px-3 text-right font-medium">Lost Links</th>
                      <th className="py-2.5 px-3 text-right font-medium">Referring Domains</th>
                      <th className="py-2.5 px-3 text-right font-medium">Net Domain Δ</th>
                      <th className="py-2.5 px-4 text-center font-medium">Authority Rank</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60 font-mono">
                    {timeline.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="py-8 text-center text-slate-500 font-sans">
                          No historical snapshots available yet. Click &quot;Refresh Backlinks&quot; to trigger a fresh timeseries pull.
                        </td>
                      </tr>
                    ) : (
                      timeline.slice().reverse().map((item) => (
                        <tr key={item.date} className="hover:bg-slate-800/30 transition text-slate-300">
                          <td className="py-2.5 px-4 font-sans text-white font-medium flex items-center gap-2">
                            <Calendar className="h-3 w-3 text-slate-500" />
                            <span>{item.date}</span>
                          </td>
                          <td className="py-2.5 px-3 text-right font-bold text-white">
                            {item.backlinks.toLocaleString()}
                          </td>
                          <td className="py-2.5 px-3 text-right text-emerald-400">
                            +{item.new_backlinks.toLocaleString()}
                          </td>
                          <td className="py-2.5 px-3 text-right text-rose-400">
                            -{item.lost_backlinks.toLocaleString()}
                          </td>
                          <td className="py-2.5 px-3 text-right text-purple-300">
                            {item.referring_domains.toLocaleString()}
                          </td>
                          <td className="py-2.5 px-3 text-right">
                            <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold ${
                              item.net_domains_delta > 0
                                ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
                                : item.net_domains_delta < 0
                                ? 'bg-rose-500/10 text-rose-400 border border-rose-500/20'
                                : 'text-slate-400'
                            }`}>
                              {item.net_domains_delta > 0 ? `+${item.net_domains_delta}` : item.net_domains_delta}
                            </span>
                          </td>
                          <td className="py-2.5 px-4 text-center text-slate-400 font-sans">
                            <span className="font-mono">{item.rank > 0 ? item.rank : '—'}</span>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* TAB: New & Lost Referring Domains */}
        {activeTab === 'new_lost' && (
          <div className="space-y-3">
            {/* Toolbar */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-slate-900/40 border border-slate-800 p-3 rounded-xl">
              <div className="relative flex-1 max-w-sm">
                <input
                  type="text"
                  value={newLostSearch}
                  onChange={(e) => setNewLostSearch(e.target.value)}
                  placeholder="Filter new or lost domains..."
                  className="w-full rounded-lg bg-slate-950 border border-slate-800 pl-8 pr-3 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
                />
                <Search className="h-3.5 w-3.5 text-slate-500 absolute left-2.5 top-2 pointer-events-none" />
              </div>

              <div className="flex items-center gap-2">
                <span className="text-xs text-slate-400 flex items-center gap-1">
                  <Filter className="h-3 w-3 text-slate-500" />
                  <span>Status:</span>
                </span>
                <div className="inline-flex rounded-lg bg-slate-950 border border-slate-800 p-0.5 text-xs">
                  <button
                    type="button"
                    onClick={() => setNewLostFilter('all')}
                    className={`px-2.5 py-1 rounded-md font-medium transition cursor-pointer ${
                      newLostFilter === 'all'
                        ? 'bg-slate-800 text-white'
                        : 'text-slate-400 hover:text-white'
                    }`}
                  >
                    All ({newDomains.length + lostDomains.length})
                  </button>
                  <button
                    type="button"
                    onClick={() => setNewLostFilter('new')}
                    className={`px-2.5 py-1 rounded-md font-medium transition cursor-pointer ${
                      newLostFilter === 'new'
                        ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                        : 'text-emerald-400/80 hover:text-emerald-300'
                    }`}
                  >
                    New ({newDomains.length})
                  </button>
                  <button
                    type="button"
                    onClick={() => setNewLostFilter('lost')}
                    className={`px-2.5 py-1 rounded-md font-medium transition cursor-pointer ${
                      newLostFilter === 'lost'
                        ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30'
                        : 'text-rose-400/80 hover:text-rose-300'
                    }`}
                  >
                    Lost ({lostDomains.length})
                  </button>
                </div>
              </div>
            </div>

            {/* Table */}
            <div className="rounded-xl border border-slate-800 bg-slate-900/40 overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-950/60 border-b border-slate-800 text-slate-400">
                    <tr>
                      <th className="py-3 px-4 font-medium">Domain</th>
                      <th className="py-3 px-3 text-center font-medium">Event</th>
                      <th className="py-3 px-3 font-medium">Event Date</th>
                      <th className="py-3 px-3 text-center font-medium">Domain Rank</th>
                      <th className="py-3 px-3 text-right font-medium">Backlinks</th>
                      <th className="py-3 px-3 text-right font-medium">Dofollow %</th>
                      <th className="py-3 px-4 text-center font-medium">Spam Score</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60">
                    {combinedNewLost.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="py-8 text-center text-slate-500">
                          No new or lost referring domains detected in the past {daysWindow} days.
                        </td>
                      </tr>
                    ) : (
                      combinedNewLost.map((item) => (
                        <tr key={`${item.domain}-${item.event_type}`} className="hover:bg-slate-800/30 transition text-slate-300">
                          <td className="py-3 px-4 font-medium text-white">
                            <div className="flex items-center gap-2">
                              <Globe className="h-3.5 w-3.5 text-purple-400 shrink-0" />
                              <span className="font-mono text-xs">{item.domain}</span>
                              <a
                                href={`https://${item.domain}`}
                                target="_blank"
                                rel="noreferrer"
                                className="text-slate-500 hover:text-slate-300 transition"
                              >
                                <ExternalLink className="h-3 w-3" />
                              </a>
                            </div>
                          </td>

                          <td className="py-3 px-3 text-center">
                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase border ${
                              item.event_type === 'new'
                                ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30'
                                : 'bg-rose-500/20 text-rose-300 border-rose-500/30'
                            }`}>
                              {item.event_type}
                            </span>
                          </td>

                          <td className="py-3 px-3 text-slate-400 font-mono text-[11px]">
                            {new Date(item.event_date).toLocaleDateString()}
                          </td>

                          <td className="py-3 px-3 text-center font-mono">
                            <span className="inline-flex items-center px-2 py-0.5 rounded bg-slate-800 text-slate-200 border border-slate-700 text-[11px] font-semibold">
                              {item.rank > 0 ? item.rank : '—'}
                            </span>
                          </td>

                          <td className="py-3 px-3 text-right font-mono font-semibold text-white">
                            {item.backlinks.toLocaleString()}
                          </td>

                          <td className="py-3 px-3 text-right font-mono text-emerald-400">
                            {item.dofollow_ratio}%
                          </td>

                          <td className="py-3 px-4 text-center">
                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-[11px] font-mono font-medium ${
                              item.spam_score >= 60
                                ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40 font-bold'
                                : item.spam_score >= 30
                                ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40'
                                : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
                            }`}>
                              {item.spam_score}%
                            </span>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* TAB 2: Top Linked Pages */}
        {activeTab === 'pages' && (
          <div className="space-y-3">
            {/* Toolbar */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-slate-900/40 border border-slate-800 p-3 rounded-xl">
              <div className="flex items-center gap-2 flex-1 max-w-md">
                <div className="relative flex-1">
                  <input
                    type="text"
                    value={pageSearch}
                    onChange={(e) => setPageSearch(e.target.value)}
                    placeholder="Search by URL or path..."
                    className="w-full rounded-lg bg-slate-950 border border-slate-800 pl-8 pr-3 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
                  />
                  <Search className="h-3.5 w-3.5 text-slate-500 absolute left-2.5 top-2 pointer-events-none" />
                </div>

                <select
                  value={pageStatusFilter}
                  onChange={(e) => setPageStatusFilter(e.target.value as 'all' | 'broken' | 'ok')}
                  className="rounded-lg bg-slate-950 border border-slate-800 px-2.5 py-1.5 text-xs text-slate-300 focus:outline-none focus:border-purple-500"
                >
                  <option value="all">All Pages</option>
                  <option value="broken">404 Broken Only</option>
                  <option value="ok">Working Only</option>
                </select>
              </div>

              <div className="text-xs text-slate-400">
                <span>Showing {filteredPages.length} of {topPages.length} pages</span>
              </div>
            </div>

            {/* Pages Table */}
            <div className="rounded-xl border border-slate-800 bg-slate-900/40 overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-950/60 border-b border-slate-800 text-slate-400 uppercase font-mono text-[10px] tracking-wider">
                    <tr>
                      <th className="py-3 px-4">Target URL</th>
                      <th className="py-3 px-3 text-center">Crawl Status</th>
                      <th className="py-3 px-3 text-right">Backlinks</th>
                      <th className="py-3 px-3 text-right">Referring Domains</th>
                      <th className="py-3 px-3 text-center">Page Rank</th>
                      <th className="py-3 px-4 text-right">Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60 font-sans">
                    {filteredPages.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="py-8 text-center text-slate-500 text-xs">
                          No pages matched your criteria.
                        </td>
                      </tr>
                    ) : (
                      filteredPages.map((p) => (
                        <tr
                          key={p.url}
                          className={`hover:bg-slate-800/30 transition text-slate-300 ${
                            p.is_broken ? 'bg-rose-500/5' : ''
                          }`}
                        >
                          {/* URL */}
                          <td className="py-3 px-4 font-medium text-white max-w-md">
                            <div className="flex items-center gap-2">
                              <span className="font-mono text-xs truncate" title={p.url}>
                                {p.path}
                              </span>
                              <button
                                type="button"
                                onClick={() => handleCopy(p.url)}
                                className="text-slate-500 hover:text-slate-300 transition shrink-0"
                                title="Copy URL"
                              >
                                {copiedUrl === p.url ? (
                                  <CheckCircle2 className="h-3 w-3 text-emerald-400" />
                                ) : (
                                  <Copy className="h-3 w-3" />
                                )}
                              </button>
                            </div>
                            <span className="text-[11px] text-slate-500 truncate block">
                              {p.url}
                            </span>
                          </td>

                          {/* Crawl Status */}
                          <td className="py-3 px-3 text-center">
                            {p.crawl_status !== null && p.crawl_status !== undefined ? (
                              <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-mono border ${
                                p.crawl_status === 200
                                  ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                                  : p.crawl_status >= 400
                                  ? 'bg-rose-500/10 text-rose-400 border-rose-500/20 font-bold'
                                  : 'bg-amber-500/10 text-amber-400 border-amber-500/20'
                              }`}>
                                {p.crawl_status} {p.crawl_status === 404 ? 'Not Found' : p.crawl_status === 200 ? 'OK' : ''}
                              </span>
                            ) : (
                              <span className="text-[10px] text-slate-500 font-mono">Uncrawled</span>
                            )}
                          </td>

                          {/* Backlinks */}
                          <td className="py-3 px-3 text-right font-mono font-semibold text-white">
                            {p.backlinks.toLocaleString()}
                          </td>

                          {/* Referring Domains */}
                          <td className="py-3 px-3 text-right font-mono text-slate-200">
                            {p.referring_domains.toLocaleString()}
                          </td>

                          {/* Page Rank */}
                          <td className="py-3 px-3 text-center font-mono text-purple-400">
                            {p.rank}
                          </td>

                          {/* Action */}
                          <td className="py-3 px-4 text-right">
                            {p.is_broken ? (
                              <span className="inline-flex items-center gap-1 text-[10px] font-semibold text-rose-400 bg-rose-500/10 border border-rose-500/20 px-2 py-0.5 rounded">
                                Redirect Needed
                              </span>
                            ) : (
                              <a
                                href={p.url}
                                target="_blank"
                                rel="noreferrer"
                                className="p-1 rounded hover:bg-slate-800 text-slate-400 hover:text-white transition inline-flex items-center"
                                title="Open URL"
                              >
                                <ExternalLink className="h-3.5 w-3.5" />
                              </a>
                            )}
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* TAB 3: Anchor Text Cloud & Table */}
        {activeTab === 'anchors' && (
          <div className="space-y-4">
            {/* Visual Anchor Cloud */}
            <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
              <span className="text-slate-400 text-xs font-medium">Top Anchor Distribution</span>
              <div className="mt-3 flex flex-wrap gap-2">
                {anchors.slice(0, 25).map((a) => (
                  <span
                    key={a.anchor}
                    className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-800/80 border border-slate-700/60 text-xs text-slate-200 hover:border-purple-500/40 transition"
                  >
                    <span className="font-medium text-white">{a.anchor}</span>
                    <span className="text-[10px] font-mono text-purple-400">
                      {a.backlinks.toLocaleString()} links
                    </span>
                  </span>
                ))}
              </div>
            </div>

            {/* Anchor Table */}
            <div className="rounded-xl border border-slate-800 bg-slate-900/40 overflow-hidden">
              <div className="p-3 border-b border-slate-800 flex items-center justify-between">
                <div className="relative max-w-sm flex-1">
                  <input
                    type="text"
                    value={anchorSearch}
                    onChange={(e) => setAnchorSearch(e.target.value)}
                    placeholder="Search anchor texts..."
                    className="w-full rounded-lg bg-slate-950 border border-slate-800 pl-8 pr-3 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
                  />
                  <Search className="h-3.5 w-3.5 text-slate-500 absolute left-2.5 top-2 pointer-events-none" />
                </div>
                <span className="text-xs text-slate-400">
                  Showing {filteredAnchors.length} of {anchors.length} anchors
                </span>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-950/60 border-b border-slate-800 text-slate-400 uppercase font-mono text-[10px] tracking-wider">
                    <tr>
                      <th className="py-3 px-4">Anchor Text</th>
                      <th className="py-3 px-3 text-right">Backlinks</th>
                      <th className="py-3 px-3 text-right">Referring Domains</th>
                      <th className="py-3 px-3 text-center">Dofollow %</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60 font-sans">
                    {filteredAnchors.length === 0 ? (
                      <tr>
                        <td colSpan={4} className="py-8 text-center text-slate-500 text-xs">
                          No anchor texts matched your search.
                        </td>
                      </tr>
                    ) : (
                      filteredAnchors.map((a) => (
                        <tr key={a.anchor} className="hover:bg-slate-800/30 transition text-slate-300">
                          <td className="py-3 px-4 font-medium text-white">{a.anchor}</td>
                          <td className="py-3 px-3 text-right font-mono font-semibold text-white">
                            {a.backlinks.toLocaleString()}
                          </td>
                          <td className="py-3 px-3 text-right font-mono text-slate-200">
                            {a.referring_domains.toLocaleString()}
                          </td>
                          <td className="py-3 px-3 text-center">
                            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-mono bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                              {a.dofollow_ratio}%
                            </span>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* TAB 4: Broken 404 Opportunities */}
        {activeTab === 'broken' && (
          <div className="space-y-4">
            <div className="rounded-xl border border-rose-500/30 bg-rose-950/10 p-4">
              <h3 className="text-sm font-semibold text-white flex items-center gap-2">
                <ShieldAlert className="h-4 w-4 text-rose-400" />
                <span>High-Authority 404 Pages Requiring 301 Redirects</span>
              </h3>
              <p className="mt-1 text-xs text-slate-400">
                The pages below currently return 404 Not Found in your latest crawl audit, but still hold active inbound external links.
                Redirecting these targets passes equity to relevant live pages and fixes dead links for visiting users.
              </p>
            </div>

            <div className="rounded-xl border border-slate-800 bg-slate-900/40 overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-950/60 border-b border-slate-800 text-slate-400 uppercase font-mono text-[10px] tracking-wider">
                    <tr>
                      <th className="py-3 px-4">Broken Target URL</th>
                      <th className="py-3 px-3 text-center">Crawl Status</th>
                      <th className="py-3 px-3 text-right">Lost Inbound Backlinks</th>
                      <th className="py-3 px-3 text-right">Referring Domains</th>
                      <th className="py-3 px-3 text-center">Priority</th>
                      <th className="py-3 px-4 text-right">Action</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60 font-sans">
                    {brokenPages.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="py-8 text-center text-slate-500 text-xs">
                          No broken 404 pages detected! All top linked pages are functioning normally.
                        </td>
                      </tr>
                    ) : (
                      brokenPages.map((b) => (
                        <tr key={b.url} className="hover:bg-slate-800/30 transition text-slate-300 bg-rose-500/5">
                          {/* URL */}
                          <td className="py-3 px-4 font-medium text-white max-w-md">
                            <div className="flex items-center gap-2">
                              <span className="font-mono text-xs text-rose-300 font-semibold truncate" title={b.url}>
                                {b.path}
                              </span>
                              <button
                                type="button"
                                onClick={() => handleCopy(b.url)}
                                className="text-slate-500 hover:text-slate-300 transition shrink-0"
                                title="Copy broken URL"
                              >
                                {copiedUrl === b.url ? (
                                  <CheckCircle2 className="h-3 w-3 text-emerald-400" />
                                ) : (
                                  <Copy className="h-3 w-3" />
                                )}
                              </button>
                            </div>
                            <span className="text-[11px] text-slate-500 truncate block">
                              {b.url}
                            </span>
                          </td>

                          {/* Crawl Status */}
                          <td className="py-3 px-3 text-center">
                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-mono bg-rose-500/20 text-rose-300 border border-rose-500/40 font-bold">
                              404 Not Found
                            </span>
                          </td>

                          {/* Inbound Backlinks */}
                          <td className="py-3 px-3 text-right font-mono font-bold text-rose-400">
                            {b.backlinks.toLocaleString()}
                          </td>

                          {/* Referring Domains */}
                          <td className="py-3 px-3 text-right font-mono text-white">
                            {b.referring_domains.toLocaleString()}
                          </td>

                          {/* Priority */}
                          <td className="py-3 px-3 text-center">
                            <span className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] uppercase font-mono font-semibold border ${
                              b.redirect_priority === 'high'
                                ? 'bg-rose-500/20 text-rose-300 border-rose-500/40'
                                : 'bg-amber-500/20 text-amber-300 border-amber-500/40'
                            }`}>
                              {b.redirect_priority}
                            </span>
                          </td>

                          {/* Action */}
                          <td className="py-3 px-4 text-right">
                            <span className="text-[11px] text-purple-300 font-medium">
                              Map 301 Target &rarr;
                            </span>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
