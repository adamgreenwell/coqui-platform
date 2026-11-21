import { Head, Link, router } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowDown,
  ArrowLeft,
  ArrowRight,
  ArrowUp,
  BarChart3,
  CheckCircle2,
  Copy,
  ExternalLink,
  Filter,
  Globe,
  Layers,
  Link2,
  Plus,
  RefreshCw,
  Search,
  Shield,
  ShieldAlert,
  Sparkles,
  Users,
  Zap,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';
import DataProviderNotice from '../../components/DataProviderNotice';

interface ProjectDetails {
  id: string;
  name: string;
  domain: string;
  canonicalUrl: string;
}

interface IntersectItem {
  domain: string;
  rank: number;
  backlinks: number;
  spam_score: number;
  first_seen?: string | null;
  // Null (with no linked competitors) when the provider didn't say which
  // competitors this domain links to.
  intersections_count: number | null;
  linked_competitors: string[];
}

interface BrokenReclaimItem {
  competitor_domain: string;
  page_url: string;
  path: string;
  status_code: number | null;
  backlinks: number;
  referring_domains: number;
  dofollow: number;
  dofollow_ratio: number | null;
  pitch_idea: string;
}

interface IntersectMetrics {
  total_opportunities: number;
  high_authority: number;
  low_spam: number;
  competitor_count: number;
}

interface IntersectFilters {
  competitors: string[];
  min_rank: number;
  max_spam: number;
  min_intersections: number;
}

interface IntersectPageProps {
  workspace: WorkspaceSummary;
  project: ProjectDetails;
  configuredCompetitors: string[];
  activeCompetitors: string[];
  hasCompetitors: boolean;
  opportunities: IntersectItem[];
  brokenReclaim: BrokenReclaimItem[];
  metrics: IntersectMetrics;
  filters: IntersectFilters;
}

export default function BacklinkIntersect({
  workspace,
  project,
  configuredCompetitors = [],
  activeCompetitors = [],
  hasCompetitors = false,
  opportunities = [],
  brokenReclaim = [],
  metrics,
  filters,
}: IntersectPageProps) {
  const [activeTab, setActiveTab] = useState<'intersect' | 'reclaim'>('intersect');
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedCompetitors, setSelectedCompetitors] = useState<string[]>(activeCompetitors);
  const [customCompetitorInput, setCustomCompetitorInput] = useState('');
  const [minRank, setMinRank] = useState<number>(filters.min_rank ?? 0);
  const [maxSpam, setMaxSpam] = useState<number>(filters.max_spam ?? 100);
  const [minIntersections, setMinIntersections] = useState<number>(filters.min_intersections ?? 2);
  const [refreshing, setRefreshing] = useState(false);
  const [copiedText, setCopiedText] = useState<string | null>(null);
  const [addingDomain, setAddingDomain] = useState<string | null>(null);

  const handleCopy = (text: string) => {
    navigator.clipboard.writeText(text);
    setCopiedText(text);
    setTimeout(() => setCopiedText(null), 2000);
  };

  const handleToggleCompetitor = (comp: string) => {
    const exists = selectedCompetitors.includes(comp);
    let next: string[];
    if (exists) {
      next = selectedCompetitors.filter((c) => c !== comp);
    } else {
      if (selectedCompetitors.length >= 3) {
        alert('You can compare against a maximum of 3 competitors at once.');
        return;
      }
      next = [...selectedCompetitors, comp];
    }
    setSelectedCompetitors(next);
    applyFilters(next, minRank, maxSpam, minIntersections);
  };

  const handleAddCustomCompetitor = (e: React.FormEvent) => {
    e.preventDefault();
    const clean = customCompetitorInput.toLowerCase().trim().replace(/^https?:\/\//, '').replace(/\/.*$/, '');
    if (!clean) return;

    if (selectedCompetitors.includes(clean)) {
      setCustomCompetitorInput('');
      return;
    }

    if (selectedCompetitors.length >= 3) {
      alert('Maximum of 3 competitors allowed simultaneously.');
      return;
    }

    const next = [...selectedCompetitors, clean];
    setSelectedCompetitors(next);
    setCustomCompetitorInput('');
    applyFilters(next, minRank, maxSpam, minIntersections);
  };

  const applyFilters = (
    competitors: string[],
    rank: number,
    spam: number,
    intersections: number
  ) => {
    router.get(
      `/workspaces/${workspace.id}/projects/${project.id}/backlinks/intersect`,
      {
        competitors: competitors.join(','),
        min_rank: rank > 0 ? rank : undefined,
        max_spam: spam < 100 ? spam : undefined,
        min_intersections: intersections,
      },
      { preserveState: true, preserveScroll: true }
    );
  };

  const handleRefresh = () => {
    setRefreshing(true);
    router.post(
      `/workspaces/${workspace.id}/projects/${project.id}/backlinks/intersect/refresh`,
      {
        competitors: selectedCompetitors,
        min_rank: minRank,
        max_spam: maxSpam,
        min_intersections: minIntersections,
      },
      {
        onFinish: () => setRefreshing(false),
      }
    );
  };

  const handleAddToWatchlist = (domain: string) => {
    setAddingDomain(domain);
    router.post(
      `/workspaces/${workspace.id}/projects/${project.id}/backlinks/watchlist`,
      {
        source_url: `https://${domain}`,
        target_url: project.canonicalUrl,
        expected_rel: 'dofollow',
      },
      {
        onFinish: () => setAddingDomain(null),
      }
    );
  };

  const filteredOpportunities = useMemo(() => {
    const q = searchQuery.toLowerCase().trim();
    if (q === '') return opportunities;
    return opportunities.filter((o) => o.domain.toLowerCase().includes(q));
  }, [opportunities, searchQuery]);

  const filteredBroken = useMemo(() => {
    const q = searchQuery.toLowerCase().trim();
    if (q === '') return brokenReclaim;
    return brokenReclaim.filter(
      (b) => b.page_url.toLowerCase().includes(q) || b.competitor_domain.toLowerCase().includes(q)
    );
  }, [brokenReclaim, searchQuery]);

  return (
    <AppLayout currentWorkspace={workspace}>
      <Head title={`${project.name} - Competitor Link Intersect & Broken Reclaim`} />

      <div className="space-y-6">
        <DataProviderNotice />
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
          <Link
            href={`/workspaces/${workspace.id}/projects/${project.id}/backlinks`}
            className="hover:text-white transition"
          >
            Backlink Intelligence
          </Link>
          <span>/</span>
          <span className="text-white font-medium">Link Intersect & Reclaim</span>
        </div>

        {/* Page Header */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-slate-800 pb-6">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
                <Sparkles className="h-6 w-6 text-sky-400" />
                <span>Competitor Link Intersect & Broken Backlink Reclaim</span>
              </h1>
              <span className="rounded-full bg-emerald-500/10 border border-emerald-500/20 px-2.5 py-0.5 text-xs font-mono text-emerald-400">
                14-Day Cache ($0 Duplicate)
              </span>
            </div>
            <p className="mt-1 text-xs text-slate-400">
              Discover referring domains linking to multiple competitors but not <span className="text-white font-mono">{project.domain}</span>,
              plus broken 404 pages on competitor domains receiving live backlinks for reclamation outreach.
            </p>
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            <Link
              href={`/workspaces/${workspace.id}/projects/${project.id}/backlinks`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer"
            >
              <Link2 className="h-3.5 w-3.5 text-purple-400" />
              <span>Backlink Explorer</span>
            </Link>

            <Link
              href={`/workspaces/${workspace.id}/projects/${project.id}/backlinks/watchlist`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer"
            >
              <ShieldAlert className="h-3.5 w-3.5 text-purple-400" />
              <span>Active Watchlist</span>
            </Link>

            <button
              type="button"
              onClick={handleRefresh}
              disabled={refreshing || selectedCompetitors.length === 0}
              className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold transition cursor-pointer disabled:opacity-50 shadow-lg shadow-sky-600/20"
            >
              <RefreshCw className={`h-3.5 w-3.5 ${refreshing ? 'animate-spin' : ''}`} />
              <span>{refreshing ? 'Refreshing...' : 'Refresh Intersect'}</span>
            </button>
          </div>
        </div>

        {/* Competitor Selector Strip */}
        <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4 space-y-3">
          <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <span className="text-xs font-medium text-slate-300 flex items-center gap-1.5">
              <Users className="h-4 w-4 text-sky-400" />
              <span>Competitor Domains to Intersect Against (Up to 3):</span>
            </span>
            <span className="text-[11px] text-slate-500">
              Excluding project domain: <span className="font-mono text-slate-300 font-semibold">{project.domain}</span>
            </span>
          </div>

          <div className="flex items-center gap-2 flex-wrap">
            {configuredCompetitors.map((comp) => {
              const isSelected = selectedCompetitors.includes(comp);
              return (
                <button
                  key={comp}
                  type="button"
                  onClick={() => handleToggleCompetitor(comp)}
                  className={`px-3 py-1.5 rounded-lg text-xs font-mono font-medium transition cursor-pointer border flex items-center gap-1.5 ${
                    isSelected
                      ? 'bg-sky-500/20 text-sky-300 border-sky-500/40 font-bold'
                      : 'bg-slate-950 text-slate-400 border-slate-800 hover:border-slate-700 hover:text-white'
                  }`}
                >
                  <Globe className="h-3 w-3" />
                  <span>{comp}</span>
                  {isSelected ? (
                    <span className="text-sky-400 text-[10px] ml-1">&times;</span>
                  ) : (
                    <span className="text-slate-600 text-[10px] ml-1">+</span>
                  )}
                </button>
              );
            })}

            {/* Custom Competitor Input */}
            <form onSubmit={handleAddCustomCompetitor} className="flex items-center gap-1.5">
              <input
                type="text"
                value={customCompetitorInput}
                onChange={(e) => setCustomCompetitorInput(e.target.value)}
                placeholder="Add custom competitor..."
                className="rounded-lg bg-slate-950 border border-slate-800 px-3 py-1 text-xs text-white placeholder-slate-600 focus:outline-none focus:border-sky-500 font-mono w-48"
              />
              <button
                type="submit"
                className="px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer border border-slate-700"
              >
                Add
              </button>
            </form>
          </div>
        </div>

        {/* KPI Metrics Grid */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
              <Sparkles className="h-3.5 w-3.5 text-sky-400" />
              <span>Intersect Opportunities</span>
            </span>
            <div className="mt-2 text-2xl font-bold font-mono text-white">
              {metrics.total_opportunities}
            </div>
            <div className="mt-1 text-[11px] text-slate-400">
              Domains linking to competitors
            </div>
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
              <Shield className="h-3.5 w-3.5 text-purple-400" />
              <span>High Authority (Rank &gt; 500)</span>
            </span>
            <div className="mt-2 text-2xl font-bold font-mono text-purple-400">
              {metrics.high_authority}
            </div>
            <div className="mt-1 text-[11px] text-slate-400">
              Tier-1 outreach targets
            </div>
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
              <CheckCircle2 className="h-3.5 w-3.5 text-emerald-400" />
              <span>Clean Profile (Spam &le; 20%)</span>
            </span>
            <div className="mt-2 text-2xl font-bold font-mono text-emerald-400">
              {metrics.low_spam}
            </div>
            <div className="mt-1 text-[11px] text-slate-400">
              Safe link acquisition candidates
            </div>
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
              <AlertTriangle className="h-3.5 w-3.5 text-rose-400" />
              <span>Competitor 404 Pages</span>
            </span>
            <div className="mt-2 text-2xl font-bold font-mono text-rose-400">
              {brokenReclaim.length}
            </div>
            <div className="mt-1 text-[11px] text-slate-400">
              Broken backlink reclaim targets
            </div>
          </div>
        </div>

        {/* View Tabs & Filters */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-800 pb-3">
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => setActiveTab('intersect')}
              className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition cursor-pointer ${
                activeTab === 'intersect'
                  ? 'bg-sky-500/20 text-sky-300 border border-sky-500/30'
                  : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50'
              }`}
            >
              <Sparkles className="h-3.5 w-3.5" />
              <span>Domain Intersect Matrix ({opportunities.length})</span>
            </button>

            <button
              type="button"
              onClick={() => setActiveTab('reclaim')}
              className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition cursor-pointer ${
                activeTab === 'reclaim'
                  ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30'
                  : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/50'
              }`}
            >
              <AlertTriangle className="h-3.5 w-3.5" />
              <span>Competitor Broken Reclaim ({brokenReclaim.length})</span>
            </button>
          </div>

          {/* Threshold Filters */}
          {activeTab === 'intersect' && (
            <div className="flex items-center gap-3 text-xs flex-wrap">
              <div className="flex items-center gap-1 bg-slate-900 border border-slate-800 rounded-lg p-1">
                <span className="text-[11px] text-slate-400 px-1">Intersects:</span>
                {[1, 2, 3].map((val) => (
                  <button
                    key={val}
                    type="button"
                    onClick={() => {
                      setMinIntersections(val);
                      applyFilters(selectedCompetitors, minRank, maxSpam, val);
                    }}
                    className={`px-2 py-0.5 rounded text-[11px] font-mono transition cursor-pointer ${
                      minIntersections === val
                        ? 'bg-sky-600 text-white font-bold'
                        : 'text-slate-400 hover:text-white'
                    }`}
                  >
                    &ge;{val}
                  </button>
                ))}
              </div>

              <div className="flex items-center gap-1.5">
                <span className="text-[11px] text-slate-400">Max Spam:</span>
                <select
                  value={maxSpam}
                  onChange={(e) => {
                    const val = Number(e.target.value);
                    setMaxSpam(val);
                    applyFilters(selectedCompetitors, minRank, val, minIntersections);
                  }}
                  className="rounded bg-slate-900 border border-slate-800 px-2 py-1 text-xs text-white focus:outline-none focus:border-sky-500 font-mono"
                >
                  <option value="100">All (100%)</option>
                  <option value="50">&le; 50%</option>
                  <option value="30">&le; 30%</option>
                  <option value="15">&le; 15%</option>
                </select>
              </div>
            </div>
          )}
        </div>

        {/* TAB 1: Domain Intersect Matrix */}
        {activeTab === 'intersect' && (
          <div className="space-y-3">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-slate-900/40 border border-slate-800 p-3 rounded-xl">
              <div className="relative flex-1 max-w-sm">
                <input
                  type="text"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  placeholder="Filter intersect domains..."
                  className="w-full rounded-lg bg-slate-950 border border-slate-800 pl-8 pr-3 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-sky-500 transition"
                />
                <Search className="h-3.5 w-3.5 text-slate-500 absolute left-2.5 top-2 pointer-events-none" />
              </div>

              <span className="text-xs text-slate-400">
                Showing {filteredOpportunities.length} of {opportunities.length} intersect domains
              </span>
            </div>

            <div className="rounded-xl border border-slate-800 bg-slate-900/40 overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-950/60 border-b border-slate-800 text-slate-400">
                    <tr>
                      <th className="py-3 px-4 font-medium">Referring Domain</th>
                      <th className="py-3 px-3 text-center font-medium">Domain Rank</th>
                      <th className="py-3 px-3 text-center font-medium">Spam Score</th>
                      <th className="py-3 px-3 font-medium">Competitors Linked</th>
                      <th className="py-3 px-3 text-right font-medium">Total Backlinks</th>
                      <th className="py-3 px-4 text-right font-medium">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60">
                    {filteredOpportunities.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="py-12 text-center text-slate-500">
                          <div className="max-w-sm mx-auto space-y-2">
                            <Sparkles className="h-8 w-8 text-slate-600 mx-auto" />
                            <p className="text-sm font-medium text-slate-300">No intersect opportunities found</p>
                            <p className="text-xs text-slate-500">
                              {selectedCompetitors.length < 2
                                ? 'Select at least 2 competitors above to run a link intersect analysis.'
                                : 'Try lowering the minimum intersections filter or increasing the spam score ceiling.'}
                            </p>
                          </div>
                        </td>
                      </tr>
                    ) : (
                      filteredOpportunities.map((item) => (
                        <tr key={item.domain} className="hover:bg-slate-800/30 transition text-slate-300">
                          {/* Domain */}
                          <td className="py-3 px-4 font-medium text-white">
                            <div className="flex items-center gap-2">
                              <Globe className="h-3.5 w-3.5 text-sky-400 shrink-0" />
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

                          {/* Domain Authority Rank */}
                          <td className="py-3 px-3 text-center">
                            <span className="inline-flex items-center px-2 py-0.5 rounded bg-slate-800 text-slate-200 border border-slate-700 text-[11px] font-mono font-semibold">
                              {item.rank > 0 ? item.rank : '—'}
                            </span>
                          </td>

                          {/* Spam Score */}
                          <td className="py-3 px-3 text-center">
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

                          {/* Linked Competitors */}
                          <td className="py-3 px-3">
                            <div className="flex items-center gap-1 flex-wrap">
                              {item.intersections_count === null ? (
                                <span className="text-[11px] text-slate-500">Unknown</span>
                              ) : (
                                item.linked_competitors.map((comp) => (
                                  <span
                                    key={comp}
                                    className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono bg-sky-500/10 text-sky-300 border border-sky-500/20"
                                  >
                                    {comp}
                                  </span>
                                ))
                              )}
                            </div>
                          </td>

                          {/* Backlinks */}
                          <td className="py-3 px-3 text-right font-mono font-semibold text-white">
                            {item.backlinks.toLocaleString()}
                          </td>

                          {/* Action: Add to Watchlist */}
                          <td className="py-3 px-4 text-right">
                            <button
                              type="button"
                              onClick={() => handleAddToWatchlist(item.domain)}
                              disabled={addingDomain === item.domain}
                              className="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-purple-600/20 hover:bg-purple-600 text-purple-300 hover:text-white border border-purple-500/40 text-[11px] font-medium transition cursor-pointer disabled:opacity-50"
                              title="Add this domain to active backlink watchlist"
                            >
                              <Plus className="h-3 w-3" />
                              <span>{addingDomain === item.domain ? 'Adding...' : 'Track'}</span>
                            </button>
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

        {/* TAB 2: Competitor Broken Reclaim */}
        {activeTab === 'reclaim' && (
          <div className="space-y-3">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-slate-900/40 border border-slate-800 p-3 rounded-xl">
              <div className="relative flex-1 max-w-sm">
                <input
                  type="text"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  placeholder="Filter competitor broken targets..."
                  className="w-full rounded-lg bg-slate-950 border border-slate-800 pl-8 pr-3 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
                />
                <Search className="h-3.5 w-3.5 text-slate-500 absolute left-2.5 top-2 pointer-events-none" />
              </div>

              <span className="text-xs text-slate-400">
                {filteredBroken.length} broken targets identified on competitor domains
              </span>
            </div>

            <div className="rounded-xl border border-slate-800 bg-slate-900/40 overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs">
                  <thead className="bg-slate-950/60 border-b border-slate-800 text-slate-400">
                    <tr>
                      <th className="py-3 px-4 font-medium">Competitor & Dead Target URL</th>
                      <th className="py-3 px-3 text-center font-medium">Status</th>
                      <th className="py-3 px-3 text-right font-medium">Inbound Backlinks</th>
                      <th className="py-3 px-3 text-right font-medium">Referring Domains</th>
                      <th className="py-3 px-3 text-right font-medium">Dofollow %</th>
                      <th className="py-3 px-4 font-medium">Outreach Pitch Hook</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60">
                    {filteredBroken.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="py-12 text-center text-slate-500">
                          <div className="max-w-sm mx-auto space-y-2">
                            <AlertTriangle className="h-8 w-8 text-slate-600 mx-auto" />
                            <p className="text-sm font-medium text-slate-300">No broken competitor backlinks found</p>
                            <p className="text-xs text-slate-500">
                              No 404 pages with incoming backlinks were detected on the active competitors.
                            </p>
                          </div>
                        </td>
                      </tr>
                    ) : (
                      filteredBroken.map((item) => (
                        <tr key={item.page_url} className="hover:bg-slate-800/30 transition text-slate-300">
                          {/* Competitor & URL */}
                          <td className="py-3 px-4 max-w-sm">
                            <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-800 text-slate-400 border border-slate-700 block w-fit mb-1">
                              {item.competitor_domain}
                            </span>
                            <div className="flex items-center gap-1.5">
                              <span className="font-mono text-xs text-rose-300 font-semibold truncate" title={item.page_url}>
                                {item.path}
                              </span>
                              <a
                                href={item.page_url}
                                target="_blank"
                                rel="noreferrer"
                                className="text-slate-500 hover:text-slate-300 transition shrink-0"
                              >
                                <ExternalLink className="h-3 w-3" />
                              </a>
                            </div>
                          </td>

                          {/* Status */}
                          <td className="py-3 px-3 text-center">
                            <span className="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono font-bold bg-rose-500/20 text-rose-300 border border-rose-500/30">
                              {item.status_code ?? 'Broken'}
                            </span>
                          </td>

                          {/* Backlinks */}
                          <td className="py-3 px-3 text-right font-mono font-bold text-purple-400">
                            {item.backlinks.toLocaleString()}
                          </td>

                          {/* Referring Domains */}
                          <td className="py-3 px-3 text-right font-mono text-white">
                            {item.referring_domains.toLocaleString()}
                          </td>

                          {/* Dofollow % */}
                          <td className="py-3 px-3 text-right font-mono text-emerald-400">
                            {item.dofollow_ratio === null ? 'Unknown' : `${item.dofollow_ratio}%`}
                          </td>

                          {/* Pitch Hook */}
                          <td className="py-3 px-4 max-w-md">
                            <div className="flex items-start justify-between gap-2 bg-slate-950 p-2 rounded-lg border border-slate-800">
                              <p className="text-[11px] text-slate-300 italic truncate" title={item.pitch_idea}>
                                &ldquo;{item.pitch_idea}&rdquo;
                              </p>
                              <button
                                type="button"
                                onClick={() => handleCopy(item.pitch_idea)}
                                className="text-slate-400 hover:text-white transition shrink-0 p-0.5"
                                title="Copy pitch hook"
                              >
                                {copiedText === item.pitch_idea ? (
                                  <CheckCircle2 className="h-3.5 w-3.5 text-emerald-400" />
                                ) : (
                                  <Copy className="h-3.5 w-3.5" />
                                )}
                              </button>
                            </div>
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
