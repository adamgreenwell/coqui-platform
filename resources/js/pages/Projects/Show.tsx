import { Head, Link, router, useForm } from '@inertiajs/react';
import {
  Activity,
  AlertTriangle,
  ArrowLeft,
  CheckCircle2,
  Clock,
  ExternalLink,
  FolderKanban,
  Globe,
  Key,
  Laptop,
  Plus,
  Radio,
  RefreshCw,
  Search,
  Settings,
  Shield,
  Trash2,
  Users,
} from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';
import { getEcho } from '../../echo';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface ProjectDetails {
  id: number;
  name: string;
  domain: string;
  canonical_url: string;
  location_code: number;
  language_code: string;
  crawl_cadence: string;
  runnr_site_id?: string | null;
  last_crawled_at?: string | null;
  created_at?: string | null;
}

interface LatestCrawlSummary {
  id: number;
  externalCrawlId: string;
  status: string;
  source: string;
  totalUrls: number;
  errorCount: number;
  warningCount: number;
  opportunityCount: number;
  finalizedAt?: string | null;
  createdAt?: string | null;
}

interface CompetitorItem {
  id: number;
  domain: string;
  label?: string | null;
  sort_order: number;
}

interface ActivityItem {
  id: number;
  eventType: string;
  title: string;
  description?: string | null;
  properties?: Record<string, unknown> | null;
  userName?: string | null;
  createdAt: string;
}

interface ProjectShowProps {
  workspace: WorkspaceSummary;
  project: ProjectDetails;
  latestCrawl?: LatestCrawlSummary | null;
  trackedKeywordsCount: number;
  monitoredBacklinksCount: number;
  competitors: CompetitorItem[];
  activities?: ActivityItem[];
  canEdit: boolean;
  canDelete: boolean;
}

function getActivityIcon(type: string) {
  if (type.startsWith('crawl.')) {
    return <RefreshCw className="h-3.5 w-3.5 text-emerald-400" />;
  }
  if (type.startsWith('competitor.')) {
    return <Users className="h-3.5 w-3.5 text-purple-400" />;
  }
  if (type.startsWith('serp.')) {
    return <Search className="h-3.5 w-3.5 text-amber-400" />;
  }
  if (type.startsWith('backlink.')) {
    return <Shield className="h-3.5 w-3.5 text-sky-400" />;
  }
  return <FolderKanban className="h-3.5 w-3.5 text-slate-400" />;
}

function formatRelativeTime(dateString: string): string {
  try {
    const date = new Date(dateString);
    const now = new Date();
    const diffSec = Math.floor((now.getTime() - date.getTime()) / 1000);

    if (diffSec < 60) return 'just now';
    if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
    if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
    return `${Math.floor(diffSec / 86400)}d ago`;
  } catch {
    return dateString;
  }
}

function formatProperties(props: Record<string, unknown>): string {
  const parts: string[] = [];
  if (typeof props.total_urls === 'number') parts.push(`${props.total_urls} URLs`);
  if (typeof props.error_count === 'number') parts.push(`${props.error_count} errors`);
  if (typeof props.domain === 'string') parts.push(props.domain);
  if (typeof props.keyword_count === 'number') parts.push(`${props.keyword_count} keywords`);
  if (typeof props.severity === 'string') parts.push(`severity: ${props.severity}`);

  if (parts.length > 0) return parts.join(' • ');

  try {
    const str = JSON.stringify(props);
    return str.length > 50 ? `${str.slice(0, 50)}...` : str;
  } catch {
    return '';
  }
}

export default function ProjectShow({
  workspace,
  project,
  latestCrawl,
  trackedKeywordsCount,
  monitoredBacklinksCount,
  competitors,
  activities = [],
  canEdit,
  canDelete,
}: ProjectShowProps) {
  const [addCompetitorModal, setAddCompetitorModal] = useState(false);
  const [liveActivities, setLiveActivities] = useState<ActivityItem[]>(activities);
  const [liveCrawl, setLiveCrawl] = useState<LatestCrawlSummary | null>(latestCrawl || null);
  const [activeFilter, setActiveFilter] = useState<'all' | 'crawls' | 'competitors' | 'project'>('all');

  const { data, setData, post, processing, errors, reset } = useForm({
    domain: '',
    label: '',
  });

  useEffect(() => {
    setLiveActivities(activities);
  }, [activities]);

  useEffect(() => {
    setLiveCrawl(latestCrawl || null);
  }, [latestCrawl]);

  useEffect(() => {
    const echo = getEcho();
    if (!echo) return;

    const channel = echo.private(`project.${project.id}`);

    channel.listen('.ProjectActivityLogged', (e: {
      id: number;
      event_type: string;
      title: string;
      description?: string | null;
      properties?: Record<string, unknown> | null;
      user_name?: string | null;
      created_at: string;
    }) => {
      const mapped: ActivityItem = {
        id: e.id,
        eventType: e.event_type,
        title: e.title,
        description: e.description,
        properties: e.properties,
        userName: e.user_name,
        createdAt: e.created_at,
      };

      setLiveActivities((prev) => [mapped, ...prev.filter((a) => a.id !== mapped.id)]);
    });

    channel.listen('.CrawlProgressUpdated', (e: {
      external_crawl_id: string;
      crawled_urls: number;
      total_urls: number;
      error_count: number;
      warning_count: number;
      opportunity_count: number;
      status: string;
      updated_at: string;
    }) => {
      setLiveCrawl((prev) => ({
        id: prev?.id || 0,
        externalCrawlId: e.external_crawl_id,
        status: e.status,
        source: prev?.source || 'desktop',
        totalUrls: e.total_urls,
        errorCount: e.error_count,
        warningCount: e.warning_count,
        opportunityCount: e.opportunity_count,
        finalizedAt: e.status === 'completed' ? e.updated_at : prev?.finalizedAt,
        createdAt: prev?.createdAt || e.updated_at,
      }));
    });

    return () => {
      channel.stopListening('.ProjectActivityLogged');
      channel.stopListening('.CrawlProgressUpdated');
    };
  }, [project.id]);

  const filteredActivities = liveActivities.filter((act) => {
    if (activeFilter === 'all') return true;
    if (activeFilter === 'crawls') return act.eventType.startsWith('crawl.');
    if (activeFilter === 'competitors') return act.eventType.startsWith('competitor.');
    if (activeFilter === 'project') return act.eventType.startsWith('project.');
    return true;
  });

  const handleAddCompetitor = (e: FormEvent) => {
    e.preventDefault();
    post(`/workspaces/${workspace.id}/projects/${project.id}/competitors`, {
      onSuccess: () => {
        setAddCompetitorModal(false);
        reset();
      },
    });
  };

  const handleDeleteCompetitor = (competitor: CompetitorItem) => {
    if (confirm(`Remove competitor ${competitor.domain}?`)) {
      router.delete(
        `/workspaces/${workspace.id}/projects/${project.id}/competitors/${competitor.id}`
      );
    }
  };

  return (
    <AppLayout currentWorkspace={workspace}>
      <Head title={`${project.name} - Project Overview`} />

      <div className="space-y-8">
        {/* Navigation Breadcrumb */}
        <div className="flex items-center gap-2 text-xs text-slate-400">
          <Link
            href={`/workspaces/${workspace.id}`}
            className="hover:text-white transition flex items-center gap-1"
          >
            <ArrowLeft className="h-3 w-3" />
            <span>{workspace.name}</span>
          </Link>
          <span>/</span>
          <span className="text-white font-medium">{project.name}</span>
        </div>

        {/* Project Header */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-slate-800 pb-6">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold tracking-tight text-white">{project.name}</h1>
              <a
                href={project.canonical_url}
                target="_blank"
                rel="noreferrer"
                className="inline-flex items-center gap-1 rounded bg-slate-800 border border-slate-700 px-2 py-0.5 text-xs text-slate-300 hover:text-white transition"
              >
                <span className="font-mono">{project.domain}</span>
                <ExternalLink className="h-3 w-3 text-slate-400" />
              </a>
              <span className="rounded-full bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 text-[11px] font-mono text-emerald-400">
                {project.language_code.toUpperCase()} • LOC:{project.location_code}
              </span>
            </div>
            <p className="mt-1 text-xs text-slate-400 font-mono">
              Canonical: {project.canonical_url} • Cadence: {project.crawl_cadence}
            </p>
          </div>

          <div className="flex items-center gap-2">
            <Link
              href={`/workspaces/${workspace.id}/projects/${project.id}/keywords`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-emerald-500/10 hover:bg-emerald-500/20 border border-emerald-500/30 text-emerald-300 text-xs font-medium transition cursor-pointer"
            >
              <Search className="h-3.5 w-3.5 text-emerald-400" />
              <span>Rank Tracker</span>
            </Link>

            {canEdit && (
              <Link
                href={`/workspaces/${workspace.id}/projects/${project.id}/settings`}
                className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer"
              >
                <Settings className="h-3.5 w-3.5 text-slate-400" />
                <span>Settings</span>
              </Link>
            )}
          </div>
        </div>

        {/* Metric Cards Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          {/* Latest Crawl Health */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <div className="flex items-center justify-between text-slate-400 text-xs">
              <span className="flex items-center gap-1.5">
                <Globe className="h-4 w-4 text-emerald-400" />
                <span>Crawl Health</span>
              </span>
              {liveCrawl && (
                <span className="capitalize px-1.5 py-0.5 rounded text-[10px] bg-slate-800 text-slate-300 border border-slate-700">
                  {liveCrawl.status}
                </span>
              )}
            </div>
            <div className="mt-3">
              {liveCrawl ? (
                <>
                  <div className="text-xl font-bold text-white font-mono">
                    {liveCrawl.totalUrls.toLocaleString()} URLs
                  </div>
                  <div className="mt-1 flex items-center gap-3 text-xs">
                    <span className="text-red-400">{liveCrawl.errorCount} errors</span>
                    <span className="text-amber-400">{liveCrawl.warningCount} warnings</span>
                  </div>
                </>
              ) : (
                <>
                  <div className="text-sm font-semibold text-slate-400">No crawls yet</div>
                  <p className="mt-1 text-[11px] text-slate-500">
                    Sync via Coqui Desktop or run cloud audit
                  </p>
                </>
              )}
            </div>
          </div>

          {/* Tracked Keywords */}
          <Link
            href={`/workspaces/${workspace.id}/projects/${project.id}/keywords`}
            className="rounded-xl border border-slate-800 bg-slate-900/40 p-4 hover:border-slate-700 transition block group cursor-pointer"
          >
            <div className="flex items-center justify-between text-slate-400 text-xs">
              <div className="flex items-center gap-1.5">
                <Search className="h-4 w-4 text-amber-400" />
                <span>Tracked Keywords</span>
              </div>
              <ExternalLink className="h-3 w-3 text-slate-500 group-hover:text-amber-400 transition" />
            </div>
            <div className="mt-3 text-xl font-bold text-white font-mono">
              {trackedKeywordsCount}
            </div>
            <p className="mt-1 text-[11px] text-slate-500">
              Active ranking monitors & SERP intelligence
            </p>
          </Link>

          {/* Monitored Backlinks */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <div className="flex items-center gap-1.5 text-slate-400 text-xs">
              <Shield className="h-4 w-4 text-sky-400" />
              <span>Monitored Backlinks</span>
            </div>
            <div className="mt-3 text-xl font-bold text-white font-mono">
              {monitoredBacklinksCount}
            </div>
            <p className="mt-1 text-[11px] text-slate-500">
              Live link status checks
            </p>
          </div>

          {/* Competitor Registry Count */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <div className="flex items-center gap-1.5 text-slate-400 text-xs">
              <Users className="h-4 w-4 text-purple-400" />
              <span>Tracked Competitors</span>
            </div>
            <div className="mt-3 text-xl font-bold text-white font-mono">
              {competitors.length} <span className="text-xs text-slate-500 font-normal">/ 5</span>
            </div>
            <p className="mt-1 text-[11px] text-slate-500">
              Competitor SERP & crawl benchmarks
            </p>
          </div>
        </div>

        {/* Competitor Registry Table */}
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-base font-semibold text-white">Tracked Competitor Domains</h2>
              <p className="text-xs text-slate-400">
                Coqui compares SERP keyword rankings, content briefs, and backlink overlaps against these competitors.
              </p>
            </div>
            {canEdit && competitors.length < 5 && (
              <button
                type="button"
                onClick={() => setAddCompetitorModal(true)}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition shadow-lg shadow-emerald-500/10 cursor-pointer"
              >
                <Plus className="h-3.5 w-3.5" />
                <span>Add Competitor</span>
              </button>
            )}
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/60 overflow-hidden shadow-sm">
            {competitors.length === 0 ? (
              <div className="p-8 text-center text-slate-500 text-xs">
                No competitor domains tracked yet. Add up to 5 competitors to compare rankings and backlink profiles.
              </div>
            ) : (
              <table className="min-w-full divide-y divide-slate-800 text-left text-xs">
                <thead className="bg-slate-950/60 text-slate-400 uppercase tracking-wider font-semibold text-[11px]">
                  <tr>
                    <th className="px-6 py-3.5">Order</th>
                    <th className="px-6 py-3.5">Domain</th>
                    <th className="px-6 py-3.5">Label</th>
                    {canEdit && <th className="px-6 py-3.5 text-right">Actions</th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800 text-slate-200">
                  {competitors.map((comp, idx) => (
                    <tr key={comp.id} className="hover:bg-slate-800/30 transition">
                      <td className="px-6 py-4 font-mono text-slate-500">#{idx + 1}</td>
                      <td className="px-6 py-4 font-semibold text-white">
                        <a
                          href={`https://${comp.domain}`}
                          target="_blank"
                          rel="noreferrer"
                          className="hover:text-emerald-400 transition inline-flex items-center gap-1"
                        >
                          <span>{comp.domain}</span>
                          <ExternalLink className="h-3 w-3 text-slate-500" />
                        </a>
                      </td>
                      <td className="px-6 py-4 text-slate-400">
                        {comp.label || '—'}
                      </td>
                      {canEdit && (
                        <td className="px-6 py-4 text-right">
                          <button
                            type="button"
                            onClick={() => handleDeleteCompetitor(comp)}
                            className="p-1 text-slate-400 hover:text-red-400 rounded transition cursor-pointer"
                            title="Remove competitor"
                          >
                            <Trash2 className="h-3.5 w-3.5" />
                          </button>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>

        {/* Realtime Event Stream & Activity Feed */}
        <div className="space-y-4">
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <div>
              <div className="flex items-center gap-2">
                <h2 className="text-base font-semibold text-white">Project Activity & Realtime Stream</h2>
                <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-[10px] font-mono text-emerald-400">
                  <span className="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse" />
                  <span>Reverb: project.{project.id}</span>
                </span>
              </div>
              <p className="text-xs text-slate-400">
                Streaming live crawl ingestion, SERP batches, competitor updates, and system activities.
              </p>
            </div>

            {/* Filter buttons */}
            <div className="flex items-center gap-1 bg-slate-900 border border-slate-800 rounded-lg p-0.5 self-start sm:self-auto text-xs">
              <button
                type="button"
                onClick={() => setActiveFilter('all')}
                className={`px-2.5 py-1 rounded-md transition ${activeFilter === 'all' ? 'bg-slate-800 text-white font-medium' : 'text-slate-400 hover:text-white'}`}
              >
                All
              </button>
              <button
                type="button"
                onClick={() => setActiveFilter('crawls')}
                className={`px-2.5 py-1 rounded-md transition ${activeFilter === 'crawls' ? 'bg-slate-800 text-white font-medium' : 'text-slate-400 hover:text-white'}`}
              >
                Crawls
              </button>
              <button
                type="button"
                onClick={() => setActiveFilter('competitors')}
                className={`px-2.5 py-1 rounded-md transition ${activeFilter === 'competitors' ? 'bg-slate-800 text-white font-medium' : 'text-slate-400 hover:text-white'}`}
              >
                Competitors
              </button>
              <button
                type="button"
                onClick={() => setActiveFilter('project')}
                className={`px-2.5 py-1 rounded-md transition ${activeFilter === 'project' ? 'bg-slate-800 text-white font-medium' : 'text-slate-400 hover:text-white'}`}
              >
                Project
              </button>
            </div>
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/60 overflow-hidden shadow-sm">
            {filteredActivities.length === 0 ? (
              <div className="p-8 text-center text-slate-500 text-xs">
                No activity recorded yet for this project. Activity will appear in real time as crawls sync or settings change.
              </div>
            ) : (
              <div className="divide-y divide-slate-800/80">
                {filteredActivities.map((act) => (
                  <div key={act.id} className="p-4 hover:bg-slate-800/20 transition flex items-start gap-3">
                    <div className="mt-0.5 p-1.5 rounded-lg bg-slate-800/80 border border-slate-700/60 shrink-0">
                      {getActivityIcon(act.eventType)}
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center justify-between gap-2">
                        <div className="flex items-center gap-2 flex-wrap">
                          <span className="text-xs font-semibold text-white">{act.title}</span>
                          <span className="rounded bg-slate-800/80 border border-slate-700 px-1.5 py-0.2 text-[10px] font-mono text-slate-400">
                            {act.eventType}
                          </span>
                        </div>
                        <span className="text-[11px] text-slate-500 whitespace-nowrap shrink-0">
                          {formatRelativeTime(act.createdAt)}
                        </span>
                      </div>
                      {act.description && (
                        <p className="mt-0.5 text-xs text-slate-400">{act.description}</p>
                      )}
                      <div className="mt-1.5 flex items-center gap-3 text-[11px] text-slate-500">
                        <span>By {act.userName || 'System / Coqui Worker'}</span>
                        {act.properties && Object.keys(act.properties).length > 0 && (
                          <span className="font-mono text-[10px] text-slate-400 bg-slate-950/60 border border-slate-800 rounded px-1.5 py-0.5">
                            {formatProperties(act.properties)}
                          </span>
                        )}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>

        {/* Coqui Desktop Sync Quickstart Banner */}
        <div className="rounded-2xl border border-slate-800 bg-slate-900/40 p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div className="flex items-start gap-4">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400">
              <Laptop className="h-5 w-5" />
            </div>
            <div>
              <h3 className="text-sm font-semibold text-white">Sync from Coqui Desktop</h3>
              <p className="mt-1 text-xs text-slate-400 max-w-xl">
                Run deep local crawls against <code className="text-emerald-400 font-mono">{project.domain}</code> in Coqui Desktop and stream results to this project ID (<code className="text-slate-300 font-mono">ID: {project.id}</code>).
              </p>
            </div>
          </div>
          <Link
            href={`/workspaces/${workspace.id}/tokens`}
            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-semibold transition shrink-0 self-start md:self-auto cursor-pointer"
          >
            <Key className="h-4 w-4 text-emerald-400" />
            <span>Get Desktop Token</span>
          </Link>
        </div>

        {/* Add Competitor Modal */}
        {addCompetitorModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
            <div className="w-full max-w-md rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl">
              <div className="flex items-center justify-between mb-4">
                <h3 className="text-base font-bold text-white flex items-center gap-2">
                  <Plus className="h-5 w-5 text-emerald-400" />
                  <span>Add Tracked Competitor</span>
                </h3>
                <button
                  type="button"
                  onClick={() => setAddCompetitorModal(false)}
                  className="text-slate-400 hover:text-white text-xs"
                >
                  ✕
                </button>
              </div>

              <form onSubmit={handleAddCompetitor} className="space-y-4">
                <div>
                  <label htmlFor="comp-domain" className="block text-xs font-medium text-slate-300">
                    Competitor Domain or URL
                  </label>
                  <input
                    id="comp-domain"
                    type="text"
                    required
                    value={data.domain}
                    onChange={(e) => setData('domain', e.target.value)}
                    className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                    placeholder="https://competitor.com"
                  />
                  {errors.domain && <p className="mt-1 text-xs text-red-400">{errors.domain}</p>}
                </div>

                <div>
                  <label htmlFor="comp-label" className="block text-xs font-medium text-slate-300">
                    Label <span className="text-slate-500">(Optional, max 100 chars)</span>
                  </label>
                  <input
                    id="comp-label"
                    type="text"
                    maxLength={100}
                    value={data.label}
                    onChange={(e) => setData('label', e.target.value)}
                    className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                    placeholder="Primary Competitor"
                  />
                  {errors.label && <p className="mt-1 text-xs text-red-400">{errors.label}</p>}
                </div>

                <div className="pt-3 flex items-center justify-end gap-2 border-t border-slate-800">
                  <button
                    type="button"
                    onClick={() => setAddCompetitorModal(false)}
                    className="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-400 hover:text-white"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={processing}
                    className="px-4 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition disabled:opacity-50 cursor-pointer"
                  >
                    {processing ? 'Adding...' : 'Add Competitor'}
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
