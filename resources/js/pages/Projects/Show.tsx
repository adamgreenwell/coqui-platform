import { Head, Link, router, useForm } from '@inertiajs/react';
import {
  Activity,
  AlertTriangle,
  ArrowLeft,
  BarChart3,
  Check,
  CheckCircle2,
  Clock,
  Copy,
  ExternalLink,
  FolderKanban,
  Globe,
  Key,
  Laptop,
  LineChart,
  Link2,
  Lock,
  Plus,
  Radio,
  RefreshCw,
  Search,
  Server,
  Settings,
  Shield,
  Sparkles,
  Trash2,
  Users,
} from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';
import { getEcho } from '../../echo';
import { FeatureLink, usePlan } from '../../components/PlanGate';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface ProjectDetails {
  id: string;
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
  id: string;
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
  id: string;
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

interface IntegrationItem {
  id: string;
  provider: string;
  site_url?: string | null;
  status: string;
  last_synced_at?: string | null;
}

interface DeploySmokeRegression {
  rule: string;
  severity: string;
  message: string;
  affected_urls: string[];
}

interface DeploySmokeSummary {
  id: string;
  externalCrawlId: string;
  status: string;
  passed: boolean;
  smokeStatus: string;
  summary: string;
  regressions: DeploySmokeRegression[];
  totalUrlsChecked: number;
  deploy?: {
    deploy_id?: string | null;
    environment?: string | null;
    commit_sha?: string | null;
    callback_url?: string | null;
  } | null;
  createdAt?: string | null;
  finalizedAt?: string | null;
}

interface ProjectShowProps {
  workspace: WorkspaceSummary;
  project: ProjectDetails;
  latestCrawl?: LatestCrawlSummary | null;
  trackedKeywordsCount: number;
  monitoredBacklinksCount: number;
  competitors: CompetitorItem[];
  integrations?: IntegrationItem[];
  deploySmoke?: DeploySmokeSummary | null;
  runnrWebhookUrl?: string;
  runnrBadgeUrl?: string;
  activities?: ActivityItem[];
  canEdit: boolean;
  canDelete: boolean;
}

function getActivityIcon(type: string) {
  if (type.startsWith('deploy.')) {
    return <Sparkles className="h-3.5 w-3.5 text-rose-400" />;
  }
  if (type.startsWith('crawl.')) {
    return <RefreshCw className="h-3.5 w-3.5 text-emerald-400" />;
  }
  if (type.startsWith('integration.')) {
    return <BarChart3 className="h-3.5 w-3.5 text-blue-400" />;
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
  integrations = [],
  deploySmoke,
  runnrWebhookUrl = '/api/webhooks/runnr/deploy',
  runnrBadgeUrl = '',
  activities = [],
  canEdit,
  canDelete,
}: ProjectShowProps) {
  const [addCompetitorModal, setAddCompetitorModal] = useState(false);
  const [bingModalOpen, setBingModalOpen] = useState(false);
  const [bingApiKey, setBingApiKey] = useState('');
  const [bingSiteUrl, setBingSiteUrl] = useState(project.canonical_url || `https://${project.domain}/`);
  const [runnrModalOpen, setRunnrModalOpen] = useState(false);
  const [runnrSiteId, setRunnrSiteId] = useState(project.runnr_site_id || '');
  const [ga4ModalOpen, setGa4ModalOpen] = useState(false);
  const [ga4PropertyId, setGa4PropertyId] = useState('');
  const [ga4ApiKey, setGa4ApiKey] = useState('');
  const [plausibleModalOpen, setPlausibleModalOpen] = useState(false);
  const [plausibleSiteId, setPlausibleSiteId] = useState(project.domain || '');
  const [plausibleApiKey, setPlausibleApiKey] = useState('');
  const [copiedBadge, setCopiedBadge] = useState(false);
  const [copiedWebhook, setCopiedWebhook] = useState(false);
  const [liveActivities, setLiveActivities] = useState<ActivityItem[]>(activities);
  const [liveCrawl, setLiveCrawl] = useState<LatestCrawlSummary | null>(latestCrawl || null);
  const [activeFilter, setActiveFilter] = useState<'all' | 'crawls' | 'competitors' | 'project'>('all');

  const gscIntegration = integrations.find((i) => i.provider === 'gsc');
  const bingIntegration = integrations.find((i) => i.provider === 'bing');
  const runnrIntegration = integrations.find((i) => i.provider === 'runnr');
  const ga4Integration = integrations.find((i) => i.provider === 'ga4');
  const plausibleIntegration = integrations.find((i) => i.provider === 'plausible');

  const { has, billingUrl } = usePlan();
  const hasTrafficAttribution = has('traffic-attribution');

  // Search and analytics integrations are part of traffic attribution. Without
  // it the card offers an upgrade instead, and existing connections can still
  // be removed.
  const lockedIntegrationActions = (integration?: IntegrationItem) => (
    <>
      <Link
        href={billingUrl}
        className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg border border-amber-500/40 bg-amber-500/10 hover:bg-amber-500/20 text-amber-300 font-medium text-xs transition cursor-pointer"
      >
        <Lock className="h-3.5 w-3.5" />
        <span>Upgrade to connect</span>
      </Link>
      {integration && (
        <button
          type="button"
          onClick={() => router.delete(`/workspaces/${workspace.id}/projects/${project.id}/integrations/${integration.id}`)}
          className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-slate-400 hover:text-red-400 hover:bg-red-500/10 text-xs font-medium transition cursor-pointer ml-auto"
        >
          <Trash2 className="h-3 w-3" />
          <span>Disconnect</span>
        </button>
      )}
    </>
  );

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
        id: prev?.id || e.external_crawl_id,
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
              feature="backlink-intelligence"
              href={`/workspaces/${workspace.id}/projects/${project.id}/backlinks`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-purple-500/10 hover:bg-purple-500/20 border border-purple-500/30 text-purple-300 text-xs font-medium transition cursor-pointer"
            >
              <Link2 className="h-3.5 w-3.5 text-purple-400" />
              <span>Backlinks</span>
            </FeatureLink>

            <FeatureLink
              feature="bot-telemetry"
              href={`/workspaces/${workspace.id}/projects/${project.id}/bot-logs`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-indigo-500/10 hover:bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 text-xs font-medium transition cursor-pointer"
            >
              <Server className="h-3.5 w-3.5 text-indigo-400" />
              <span>Bot Telemetry</span>
            </FeatureLink>

            <FeatureLink
              feature="traffic-attribution"
              href={`/workspaces/${workspace.id}/projects/${project.id}/analytics/performance`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-teal-500/10 hover:bg-teal-500/20 border border-teal-500/30 text-teal-300 text-xs font-medium transition cursor-pointer"
            >
              <LineChart className="h-3.5 w-3.5 text-teal-400" />
              <span>Traffic &amp; Performance</span>
            </FeatureLink>

            {canEdit && (
              <button
                type="button"
                onClick={() => {
                  if (confirm(`Run a cloud SEO audit on ${project.domain} now?`)) {
                    router.post(`/workspaces/${workspace.id}/projects/${project.id}/crawls/dispatch`);
                  }
                }}
                disabled={liveCrawl?.status === 'running' || liveCrawl?.status === 'pending'}
                className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-teal-500/10 hover:bg-teal-500/20 border border-teal-500/30 text-teal-300 text-xs font-medium transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <Activity className={`h-3.5 w-3.5 text-teal-400 ${liveCrawl?.status === 'running' ? 'animate-spin' : ''}`} />
                <span>{liveCrawl?.status === 'running' ? 'Crawling...' : 'Run Cloud Audit'}</span>
              </button>
            )}

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
          {/* Crawl Health */}
          <Link
            href={`/workspaces/${workspace.id}/projects/${project.id}/crawls/compare`}
            className="rounded-xl border border-slate-800 bg-slate-900/40 p-4 hover:border-slate-700 transition block group cursor-pointer"
          >
            <div className="flex items-center justify-between text-slate-400 text-xs">
              <span className="flex items-center gap-1.5 group-hover:text-emerald-400 transition">
                <Globe className="h-4 w-4 text-emerald-400" />
                <span>Crawl Health</span>
              </span>
              <div className="flex items-center gap-1.5">
                {liveCrawl && (
                  <span className="capitalize px-1.5 py-0.5 rounded text-[10px] bg-slate-800 text-slate-300 border border-slate-700">
                    {liveCrawl.status}
                  </span>
                )}
                <span className="text-[10px] font-medium text-emerald-400/80 group-hover:text-emerald-300">
                  Diff &rarr;
                </span>
              </div>
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
                    Sync via Coqui Desktop or compare snapshots
                  </p>
                </>
              )}
            </div>
          </Link>

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
            <div className="flex items-center gap-2">
              <Link
                href={`/workspaces/${workspace.id}/projects/${project.id}/keyword-gap`}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-500/10 hover:bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 text-xs font-medium transition cursor-pointer"
              >
                <Users className="h-3.5 w-3.5 text-indigo-400" />
                <span>Keyword Gap</span>
              </Link>

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

        {/* First-Party Integrations & Runnr Hosting Moat */}
        <div className="space-y-4">
          <div>
            <h2 className="text-base font-semibold text-white">First-Party Integrations &amp; Runnr Hosting Moat</h2>
            <p className="text-xs text-slate-400">
              Direct API integrations for Google Search Console, Bing Webmaster Tools, and automated Runnr hosting post-deploy SEO smoke tests.
            </p>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {/* Google Search Console Card */}
            <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-5 flex flex-col justify-between gap-4">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className="p-2 rounded-lg bg-blue-500/10 border border-blue-500/30 text-blue-400">
                    <BarChart3 className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="text-sm font-semibold text-white">Google Search Console</h3>
                    <p className="text-xs text-slate-400">
                      Query performance, impressions, real clicks, CTR &amp; average position
                    </p>
                  </div>
                </div>
                {gscIntegration?.status === 'connected' ? (
                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                    <CheckCircle2 className="h-3 w-3" /> Connected
                  </span>
                ) : gscIntegration?.status === 'needs_reconnect' ? (
                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-500/10 text-amber-400 border border-amber-500/30">
                    <AlertTriangle className="h-3 w-3" /> Reconnect
                  </span>
                ) : (
                  <span className="px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-800 text-slate-400 border border-slate-700">
                    Not Connected
                  </span>
                )}
              </div>

              {gscIntegration?.site_url && (
                <div className="text-xs text-slate-400 flex items-center gap-1 font-mono">
                  <span className="text-slate-500">Property:</span>
                  <span className="text-slate-200">{gscIntegration.site_url}</span>
                </div>
              )}

              {gscIntegration?.last_synced_at && (
                <div className="text-[11px] text-slate-500">
                  Last synced: {formatRelativeTime(gscIntegration.last_synced_at)}
                </div>
              )}

              <div className="flex items-center gap-2 pt-2 border-t border-slate-800/80">
                {canEdit && !hasTrafficAttribution && lockedIntegrationActions(gscIntegration)}
                {canEdit && hasTrafficAttribution && (
                  gscIntegration?.status === 'connected' ? (
                    <>
                      <button
                        type="button"
                        onClick={() => router.post(`/workspaces/${workspace.id}/projects/${project.id}/integrations/${gscIntegration.id}/sync`)}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium border border-slate-700 transition cursor-pointer"
                      >
                        <RefreshCw className="h-3 w-3" />
                        <span>Sync Now</span>
                      </button>
                      <button
                        type="button"
                        onClick={() => router.delete(`/workspaces/${workspace.id}/projects/${project.id}/integrations/${gscIntegration.id}`)}
                        className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-slate-400 hover:text-red-400 hover:bg-red-500/10 text-xs font-medium transition cursor-pointer ml-auto"
                      >
                        <Trash2 className="h-3 w-3" />
                        <span>Disconnect</span>
                      </button>
                    </>
                  ) : (
                    <a
                      href={`/workspaces/${workspace.id}/projects/${project.id}/integrations/google/connect`}
                      className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-blue-600 hover:bg-blue-500 text-white font-medium text-xs transition cursor-pointer shadow-md shadow-blue-500/10"
                    >
                      <Plus className="h-3.5 w-3.5" />
                      <span>{gscIntegration?.status === 'needs_reconnect' ? 'Reconnect Google Account' : 'Connect Search Console'}</span>
                    </a>
                  )
                )}
              </div>
            </div>

            {/* Bing Webmaster Tools Card */}
            <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-5 flex flex-col justify-between gap-4">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className="p-2 rounded-lg bg-sky-500/10 border border-sky-500/30 text-sky-400">
                    <Globe className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="text-sm font-semibold text-white">Bing Webmaster Tools</h3>
                    <p className="text-xs text-slate-400">
                      Bing organic search query metrics and crawl stats
                    </p>
                  </div>
                </div>
                {bingIntegration?.status === 'connected' ? (
                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                    <CheckCircle2 className="h-3 w-3" /> Connected
                  </span>
                ) : (
                  <span className="px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-800 text-slate-400 border border-slate-700">
                    Not Connected
                  </span>
                )}
              </div>

              {bingIntegration?.site_url && (
                <div className="text-xs text-slate-400 flex items-center gap-1 font-mono">
                  <span className="text-slate-500">Site:</span>
                  <span className="text-slate-200">{bingIntegration.site_url}</span>
                </div>
              )}

              {bingIntegration?.last_synced_at && (
                <div className="text-[11px] text-slate-500">
                  Last synced: {formatRelativeTime(bingIntegration.last_synced_at)}
                </div>
              )}

              <div className="flex items-center gap-2 pt-2 border-t border-slate-800/80">
                {canEdit && !hasTrafficAttribution && lockedIntegrationActions(bingIntegration)}
                {canEdit && hasTrafficAttribution && (
                  bingIntegration?.status === 'connected' ? (
                    <>
                      <button
                        type="button"
                        onClick={() => router.post(`/workspaces/${workspace.id}/projects/${project.id}/integrations/${bingIntegration.id}/sync`)}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium border border-slate-700 transition cursor-pointer"
                      >
                        <RefreshCw className="h-3 w-3" />
                        <span>Sync Now</span>
                      </button>
                      <button
                        type="button"
                        onClick={() => router.delete(`/workspaces/${workspace.id}/projects/${project.id}/integrations/${bingIntegration.id}`)}
                        className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-slate-400 hover:text-red-400 hover:bg-red-500/10 text-xs font-medium transition cursor-pointer ml-auto"
                      >
                        <Trash2 className="h-3 w-3" />
                        <span>Disconnect</span>
                      </button>
                    </>
                  ) : (
                    <button
                      type="button"
                      onClick={() => setBingModalOpen(true)}
                      className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-sky-600 hover:bg-sky-500 text-white font-medium text-xs transition cursor-pointer shadow-md shadow-sky-500/10"
                    >
                      <Plus className="h-3.5 w-3.5" />
                      <span>Connect Bing Webmaster</span>
                    </button>
                  )
                )}
              </div>
            </div>

            {/* Runnr Hosting & Post-Deploy Smoke Card */}
            <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-5 flex flex-col justify-between gap-4">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className="p-2 rounded-lg bg-indigo-500/10 border border-indigo-500/30 text-indigo-400">
                    <Server className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="text-sm font-semibold text-white">Runnr Hosting &amp; Smoke Tests</h3>
                    <p className="text-xs text-slate-400">
                      Post-deploy crawl for robots.txt regressions &amp; noindex leaks
                    </p>
                  </div>
                </div>
                {project.runnr_site_id ? (
                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-indigo-500/10 text-indigo-400 border border-indigo-500/30">
                    <CheckCircle2 className="h-3 w-3" /> Connected
                  </span>
                ) : (
                  <span className="px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-800 text-slate-400 border border-slate-700">
                    Not Connected
                  </span>
                )}
              </div>

              {project.runnr_site_id ? (
                <div className="space-y-2 text-xs">
                  <div className="text-slate-400 flex items-center gap-1 font-mono">
                    <span className="text-slate-500">Site ID:</span>
                    <span className="text-indigo-300 font-semibold">{project.runnr_site_id}</span>
                  </div>

                  {deploySmoke ? (
                    <div className="p-2.5 rounded-lg bg-slate-950/80 border border-slate-800 space-y-1.5">
                      <div className="flex items-center justify-between">
                        <span className="text-slate-400 text-[11px] font-medium">Latest Deploy Smoke:</span>
                        {deploySmoke.passed ? (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                            <CheckCircle2 className="h-2.5 w-2.5" /> Passing
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-500/10 text-rose-400 border border-rose-500/30">
                            <AlertTriangle className="h-2.5 w-2.5" /> Failing
                          </span>
                        )}
                      </div>
                      <p className="text-[11px] text-slate-300 line-clamp-2">{deploySmoke.summary}</p>
                      {deploySmoke.regressions.length > 0 && (
                        <div className="flex flex-wrap gap-1 pt-1">
                          {deploySmoke.regressions.map((reg, idx) => (
                            <span key={idx} className="px-1.5 py-0.5 rounded bg-rose-500/20 text-rose-300 text-[10px] font-mono">
                              {reg.rule}
                            </span>
                          ))}
                        </div>
                      )}
                    </div>
                  ) : (
                    <div className="text-[11px] text-slate-500">
                      No deploy smoke tests run yet. Webhook triggers on deployment.
                    </div>
                  )}

                  <div className="pt-1 flex items-center justify-between gap-1">
                    <span className="text-[11px] text-slate-400">Embed Status Badge:</span>
                    <button
                      type="button"
                      onClick={() => {
                        const md = `[![Runnr SEO Smoke](${runnrBadgeUrl})](${runnrBadgeUrl})`;
                        navigator.clipboard.writeText(md);
                        setCopiedBadge(true);
                        setTimeout(() => setCopiedBadge(false), 2000);
                      }}
                      className="inline-flex items-center gap-1 text-[11px] text-indigo-400 hover:text-indigo-300 transition cursor-pointer"
                    >
                      {copiedBadge ? <Check className="h-3 w-3 text-emerald-400" /> : <Copy className="h-3 w-3" />}
                      <span>{copiedBadge ? 'Copied Markdown' : 'Copy Badge MD'}</span>
                    </button>
                  </div>
                </div>
              ) : (
                <p className="text-xs text-slate-400">
                  Connect your Runnr site ID to trigger automated 60s post-deploy smoke crawls checking for robots.txt regressions, staging noindex leaks, and 5xx errors.
                </p>
              )}

              <div className="flex items-center gap-2 pt-2 border-t border-slate-800/80">
                {canEdit && (
                  project.runnr_site_id ? (
                    <>
                      <button
                        type="button"
                        onClick={() => setRunnrModalOpen(true)}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium border border-slate-700 transition cursor-pointer"
                      >
                        <Settings className="h-3 w-3" />
                        <span>Configure Webhook</span>
                      </button>
                      <button
                        type="button"
                        onClick={() => router.delete(`/workspaces/${workspace.id}/projects/${project.id}/integrations/runnr/disconnect`)}
                        className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-slate-400 hover:text-red-400 hover:bg-red-500/10 text-xs font-medium transition cursor-pointer ml-auto"
                      >
                        <Trash2 className="h-3 w-3" />
                        <span>Disconnect</span>
                      </button>
                    </>
                  ) : (
                    <button
                      type="button"
                      onClick={() => setRunnrModalOpen(true)}
                      className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-medium text-xs transition cursor-pointer shadow-md shadow-indigo-500/10"
                    >
                      <Plus className="h-3.5 w-3.5" />
                      <span>Connect Runnr Site</span>
                    </button>
                  )
                )}
              </div>
            </div>

            {/* Google Analytics 4 Card */}
            <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-5 flex flex-col justify-between gap-4">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className="p-2 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-400">
                    <LineChart className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="text-sm font-semibold text-white">Google Analytics 4</h3>
                    <p className="text-xs text-slate-400">
                      Landing-page organic traffic, engagement, and AI assistant referrals
                    </p>
                  </div>
                </div>
                {ga4Integration?.status === 'connected' ? (
                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                    <CheckCircle2 className="h-3 w-3" /> Connected
                  </span>
                ) : (
                  <span className="px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-800 text-slate-400 border border-slate-700">
                    Not Connected
                  </span>
                )}
              </div>

              {ga4Integration?.site_url && (
                <div className="text-xs text-slate-400 flex items-center gap-1 font-mono">
                  <span className="text-slate-500">Property:</span>
                  <span className="text-slate-200">{ga4Integration.site_url}</span>
                </div>
              )}

              {ga4Integration?.last_synced_at && (
                <div className="text-[11px] text-slate-500">
                  Last synced: {formatRelativeTime(ga4Integration.last_synced_at)}
                </div>
              )}

              <div className="flex items-center gap-2 pt-2 border-t border-slate-800/80">
                {canEdit && !hasTrafficAttribution && lockedIntegrationActions(ga4Integration)}
                {canEdit && hasTrafficAttribution && (
                  ga4Integration?.status === 'connected' ? (
                    <>
                      <button
                        type="button"
                        onClick={() => router.post(`/workspaces/${workspace.id}/projects/${project.id}/integrations/${ga4Integration.id}/sync`)}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium border border-slate-700 transition cursor-pointer"
                      >
                        <RefreshCw className="h-3 w-3" />
                        <span>Sync Now</span>
                      </button>
                      <button
                        type="button"
                        onClick={() => router.delete(`/workspaces/${workspace.id}/projects/${project.id}/integrations/${ga4Integration.id}`)}
                        className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-slate-400 hover:text-red-400 hover:bg-red-500/10 text-xs font-medium transition cursor-pointer ml-auto"
                      >
                        <Trash2 className="h-3 w-3" />
                        <span>Disconnect</span>
                      </button>
                    </>
                  ) : (
                    <button
                      type="button"
                      onClick={() => setGa4ModalOpen(true)}
                      className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-amber-600 hover:bg-amber-500 text-white font-medium text-xs transition cursor-pointer shadow-md shadow-amber-500/10"
                    >
                      <Plus className="h-3.5 w-3.5" />
                      <span>Connect GA4 Property</span>
                    </button>
                  )
                )}
              </div>
            </div>

            {/* Plausible Analytics Card */}
            <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-5 flex flex-col justify-between gap-4">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className="p-2 rounded-lg bg-violet-500/10 border border-violet-500/30 text-violet-400">
                    <Sparkles className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="text-sm font-semibold text-white">Plausible Analytics</h3>
                    <p className="text-xs text-slate-400">
                      Privacy-first organic visits and AI referrer breakdowns
                    </p>
                  </div>
                </div>
                {plausibleIntegration?.status === 'connected' ? (
                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                    <CheckCircle2 className="h-3 w-3" /> Connected
                  </span>
                ) : (
                  <span className="px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-800 text-slate-400 border border-slate-700">
                    Not Connected
                  </span>
                )}
              </div>

              {plausibleIntegration?.site_url && (
                <div className="text-xs text-slate-400 flex items-center gap-1 font-mono">
                  <span className="text-slate-500">Site ID:</span>
                  <span className="text-slate-200">{plausibleIntegration.site_url}</span>
                </div>
              )}

              {plausibleIntegration?.last_synced_at && (
                <div className="text-[11px] text-slate-500">
                  Last synced: {formatRelativeTime(plausibleIntegration.last_synced_at)}
                </div>
              )}

              <div className="flex items-center gap-2 pt-2 border-t border-slate-800/80">
                {canEdit && !hasTrafficAttribution && lockedIntegrationActions(plausibleIntegration)}
                {canEdit && hasTrafficAttribution && (
                  plausibleIntegration?.status === 'connected' ? (
                    <>
                      <button
                        type="button"
                        onClick={() => router.post(`/workspaces/${workspace.id}/projects/${project.id}/integrations/${plausibleIntegration.id}/sync`)}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium border border-slate-700 transition cursor-pointer"
                      >
                        <RefreshCw className="h-3 w-3" />
                        <span>Sync Now</span>
                      </button>
                      <button
                        type="button"
                        onClick={() => router.delete(`/workspaces/${workspace.id}/projects/${project.id}/integrations/${plausibleIntegration.id}`)}
                        className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-slate-400 hover:text-red-400 hover:bg-red-500/10 text-xs font-medium transition cursor-pointer ml-auto"
                      >
                        <Trash2 className="h-3 w-3" />
                        <span>Disconnect</span>
                      </button>
                    </>
                  ) : (
                    <button
                      type="button"
                      onClick={() => setPlausibleModalOpen(true)}
                      className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-violet-600 hover:bg-violet-500 text-white font-medium text-xs transition cursor-pointer shadow-md shadow-violet-500/10"
                    >
                      <Plus className="h-3.5 w-3.5" />
                      <span>Connect Plausible Site</span>
                    </button>
                  )
                )}
              </div>
            </div>
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

        {/* Connect Bing Webmaster Tools Modal */}
        {bingModalOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
            <div className="bg-slate-900 border border-slate-800 rounded-xl max-w-md w-full p-6 shadow-2xl">
              <div className="flex items-center justify-between pb-3 border-b border-slate-800">
                <div className="flex items-center gap-2">
                  <Globe className="h-5 w-5 text-sky-400" />
                  <h3 className="text-base font-semibold text-white">Connect Bing Webmaster Tools</h3>
                </div>
                <button
                  type="button"
                  onClick={() => setBingModalOpen(false)}
                  className="text-slate-400 hover:text-white"
                >
                  ✕
                </button>
              </div>

              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  router.post(
                    `/workspaces/${workspace.id}/projects/${project.id}/integrations/bing/connect`,
                    {
                      api_key: bingApiKey,
                      site_url: bingSiteUrl,
                    },
                    {
                      onSuccess: () => setBingModalOpen(false),
                    }
                  );
                }}
                className="space-y-4 mt-4"
              >
                <div>
                  <label htmlFor="bing-api-key" className="block text-xs font-medium text-slate-300">
                    Bing Webmaster API Key
                  </label>
                  <input
                    id="bing-api-key"
                    type="password"
                    required
                    value={bingApiKey}
                    onChange={(e) => setBingApiKey(e.target.value)}
                    className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-500/50"
                    placeholder="Enter your 32-character Bing API key"
                  />
                  <p className="mt-1 text-[11px] text-slate-500">
                    Find this in Bing Webmaster Tools &gt; Settings &gt; API Access &gt; API Key.
                  </p>
                </div>

                <div>
                  <label htmlFor="bing-site-url" className="block text-xs font-medium text-slate-300">
                    Site URL in Bing Webmaster
                  </label>
                  <input
                    id="bing-site-url"
                    type="url"
                    required
                    value={bingSiteUrl}
                    onChange={(e) => setBingSiteUrl(e.target.value)}
                    className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-500/50"
                    placeholder={`https://${project.domain}/`}
                  />
                </div>

                <div className="pt-3 flex items-center justify-end gap-2 border-t border-slate-800">
                  <button
                    type="button"
                    onClick={() => setBingModalOpen(false)}
                    className="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-400 hover:text-white"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-1.5 rounded-lg bg-sky-500 hover:bg-sky-400 text-slate-950 font-semibold text-xs transition cursor-pointer"
                  >
                    Connect Bing
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Runnr Hosting Webhook Setup Modal */}
        {runnrModalOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
            <div className="relative w-full max-w-lg rounded-xl border border-slate-800 bg-slate-900 p-6 shadow-2xl space-y-4">
              <div className="flex items-center justify-between pb-3 border-b border-slate-800">
                <div className="flex items-center gap-2">
                  <div className="p-2 rounded-lg bg-indigo-500/10 border border-indigo-500/30 text-indigo-400">
                    <Server className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="text-base font-semibold text-white">Runnr Hosting Webhook Setup</h3>
                    <p className="text-xs text-slate-400">Automated post-deploy SEO smoke testing</p>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => setRunnrModalOpen(false)}
                  className="text-slate-400 hover:text-white"
                >
                  ✕
                </button>
              </div>

              <div className="space-y-3 text-xs text-slate-300">
                <div>
                  <span className="block font-medium text-slate-400 mb-1">Webhook Endpoint URL</span>
                  <div className="flex items-center gap-2 p-2 rounded-lg bg-slate-950 border border-slate-800 font-mono text-[11px] text-slate-200">
                    <span className="flex-1 truncate">{runnrWebhookUrl}</span>
                    <button
                      type="button"
                      onClick={() => {
                        navigator.clipboard.writeText(runnrWebhookUrl);
                        setCopiedWebhook(true);
                        setTimeout(() => setCopiedWebhook(false), 2000);
                      }}
                      className="px-2 py-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-300 transition flex items-center gap-1 cursor-pointer"
                    >
                      {copiedWebhook ? <Check className="h-3 w-3 text-emerald-400" /> : <Copy className="h-3 w-3" />}
                      <span>{copiedWebhook ? 'Copied' : 'Copy'}</span>
                    </button>
                  </div>
                </div>

                <div className="p-3 rounded-lg bg-slate-950/60 border border-slate-800 text-[11px] space-y-1.5 text-slate-400">
                  <p className="font-semibold text-slate-200">How Runnr Webhooks Work:</p>
                  <p>1. In Runnr Site Settings &gt; Webhooks, add a Deploy Webhook pointing to the URL above.</p>
                  <p>2. Set your webhook secret matching <code className="text-indigo-300">RUNNR_WEBHOOK_SECRET</code>. Runnr signs requests with HMAC-SHA256 in the <code className="text-indigo-300">X-Runnr-Signature</code> header.</p>
                  <p>3. Every deploy triggers a 60-second smoke crawl checking for accidental <code className="text-rose-300">Disallow: /</code>, staging <code className="text-rose-300">noindex</code>, 5xx errors, and canonical host flips.</p>
                </div>
              </div>

              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  router.post(
                    `/workspaces/${workspace.id}/projects/${project.id}/integrations/runnr/connect`,
                    { runnr_site_id: runnrSiteId },
                    { onSuccess: () => setRunnrModalOpen(false) }
                  );
                }}
                className="space-y-4 pt-2 border-t border-slate-800"
              >
                <div>
                  <label htmlFor="runnr-site-id" className="block text-xs font-medium text-slate-300">
                    Runnr Site ID
                  </label>
                  <input
                    id="runnr-site-id"
                    type="text"
                    required
                    value={runnrSiteId}
                    onChange={(e) => setRunnrSiteId(e.target.value)}
                    className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/50"
                    placeholder="e.g. site_runnr_12345"
                  />
                  <p className="mt-1 text-[11px] text-slate-500">
                    Find this identifier in your Runnr site dashboard.
                  </p>
                </div>

                <div className="pt-3 flex items-center justify-end gap-2 border-t border-slate-800">
                  <button
                    type="button"
                    onClick={() => setRunnrModalOpen(false)}
                    className="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-400 hover:text-white"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-semibold text-xs transition cursor-pointer shadow-md shadow-indigo-500/20"
                  >
                    Save &amp; Activate
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* GA4 Setup Modal */}
        {ga4ModalOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
            <div className="relative w-full max-w-lg rounded-xl border border-slate-800 bg-slate-900 p-6 shadow-2xl space-y-4">
              <div className="flex items-center justify-between pb-3 border-b border-slate-800">
                <div className="flex items-center gap-2">
                  <div className="p-2 rounded-lg bg-amber-500/10 border border-amber-500/30 text-amber-400">
                    <LineChart className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="text-base font-semibold text-white">Connect Google Analytics 4</h3>
                    <p className="text-xs text-slate-400">Sync landing-page organic &amp; AI assistant traffic</p>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => setGa4ModalOpen(false)}
                  className="text-slate-400 hover:text-white"
                >
                  ✕
                </button>
              </div>

              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  router.post(
                    `/workspaces/${workspace.id}/projects/${project.id}/integrations/ga4/connect`,
                    {
                      property_id: ga4PropertyId,
                      api_key: ga4ApiKey,
                    },
                    { onSuccess: () => setGa4ModalOpen(false) }
                  );
                }}
                className="space-y-4 text-xs"
              >
                <div>
                  <label htmlFor="ga4-property-id" className="block text-xs font-medium text-slate-300">
                    GA4 Property ID <span className="text-rose-400">*</span>
                  </label>
                  <input
                    id="ga4-property-id"
                    type="text"
                    required
                    value={ga4PropertyId}
                    onChange={(e) => setGa4PropertyId(e.target.value)}
                    className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500/50"
                    placeholder="e.g. 123456789 or properties/123456789"
                  />
                  <p className="mt-1 text-[11px] text-slate-500">
                    Found in Google Analytics Admin &gt; Property Settings &gt; Property details.
                  </p>
                </div>

                <div>
                  <label htmlFor="ga4-api-key" className="block text-xs font-medium text-slate-300">
                    API Key or Bearer Token (optional)
                  </label>
                  <input
                    id="ga4-api-key"
                    type="password"
                    value={ga4ApiKey}
                    onChange={(e) => setGa4ApiKey(e.target.value)}
                    className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500/50"
                    placeholder="Optional service token or API secret"
                  />
                  <p className="mt-1 text-[11px] text-slate-500">
                    OAuth token or Google Cloud API key authorized for Google Analytics Data API.
                  </p>
                </div>

                <div className="pt-3 flex items-center justify-end gap-2 border-t border-slate-800">
                  <button
                    type="button"
                    onClick={() => setGa4ModalOpen(false)}
                    className="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-400 hover:text-white"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-500 text-white font-semibold text-xs transition cursor-pointer shadow-md shadow-amber-500/20"
                  >
                    Connect GA4
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Plausible Setup Modal */}
        {plausibleModalOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
            <div className="relative w-full max-w-lg rounded-xl border border-slate-800 bg-slate-900 p-6 shadow-2xl space-y-4">
              <div className="flex items-center justify-between pb-3 border-b border-slate-800">
                <div className="flex items-center gap-2">
                  <div className="p-2 rounded-lg bg-violet-500/10 border border-violet-500/30 text-violet-400">
                    <Sparkles className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="text-base font-semibold text-white">Connect Plausible Analytics</h3>
                    <p className="text-xs text-slate-400">Privacy-friendly page visits &amp; AI referrer tracking</p>
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => setPlausibleModalOpen(false)}
                  className="text-slate-400 hover:text-white"
                >
                  ✕
                </button>
              </div>

              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  router.post(
                    `/workspaces/${workspace.id}/projects/${project.id}/integrations/plausible/connect`,
                    {
                      site_id: plausibleSiteId,
                      api_key: plausibleApiKey,
                    },
                    { onSuccess: () => setPlausibleModalOpen(false) }
                  );
                }}
                className="space-y-4 text-xs"
              >
                <div>
                  <label htmlFor="plausible-site-id" className="block text-xs font-medium text-slate-300">
                    Plausible Site ID / Domain
                  </label>
                  <input
                    id="plausible-site-id"
                    type="text"
                    required
                    value={plausibleSiteId}
                    onChange={(e) => setPlausibleSiteId(e.target.value)}
                    className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50"
                    placeholder={`e.g. ${project.domain}`}
                  />
                  <p className="mt-1 text-[11px] text-slate-500">
                    The domain name registered in your Plausible dashboard.
                  </p>
                </div>

                <div>
                  <label htmlFor="plausible-api-key" className="block text-xs font-medium text-slate-300">
                    Plausible API Key / Token <span className="text-rose-400">*</span>
                  </label>
                  <input
                    id="plausible-api-key"
                    type="password"
                    required
                    value={plausibleApiKey}
                    onChange={(e) => setPlausibleApiKey(e.target.value)}
                    className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-violet-500/50"
                    placeholder="Enter Plausible API key (Stats API)"
                  />
                  <p className="mt-1 text-[11px] text-slate-500">
                    Generated in Plausible Settings &gt; API Keys with stats read access.
                  </p>
                </div>

                <div className="pt-3 flex items-center justify-end gap-2 border-t border-slate-800">
                  <button
                    type="button"
                    onClick={() => setPlausibleModalOpen(false)}
                    className="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-400 hover:text-white"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="px-4 py-1.5 rounded-lg bg-violet-600 hover:bg-violet-500 text-white font-semibold text-xs transition cursor-pointer shadow-md shadow-violet-500/20"
                  >
                    Connect Plausible
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
