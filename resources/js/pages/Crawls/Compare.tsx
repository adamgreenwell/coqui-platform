import { Head, Link, router } from '@inertiajs/react';
import {
  AlertCircle,
  AlertTriangle,
  ArrowDown,
  ArrowLeft,
  ArrowRight,
  ArrowUp,
  Check,
  CheckCircle2,
  Clock,
  Copy,
  ExternalLink,
  Flame,
  Globe,
  Layers,
  Minus,
  Plus,
  RefreshCw,
  Search,
  Share2,
  Sliders,
  Trash2,
  TrendingDown,
  TrendingUp,
  X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { UpgradePill, usePlan } from '../../components/PlanGate';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface CrawlRunOption {
  id: string;
  externalCrawlId: string;
  source: string;
  status: string;
  totalUrls: number;
  errorCount: number;
  warningCount: number;
  createdAt?: string | null;
  finalizedAt?: string | null;
  label: string;
}

interface RunSummary {
  id: string;
  externalCrawlId: string;
  seed: string;
  source: string;
  // False when this side is an imported snapshot with no issue data.
  issuesMeasured?: boolean;
  status: string;
  totalUrls: number;
  errorCount: number;
  warningCount: number;
  opportunityCount: number;
  createdAt?: string | null;
  finalizedAt?: string | null;
}

interface DeltaSummary {
  totalUrls: number;
  newUrlsCount: number;
  removedUrlsCount: number;
  newlyBrokenCount: number;
  statusChangesCount: number;
  // Null (not zero) when either side is an import: issue deltas were not measured.
  newIssuesCount: number | null;
  resolvedIssuesCount: number | null;
  persistentIssuesCount: number | null;
  trafficImpactedBrokenUrls: number;
  totalLostClicks: number;
}

interface TransitionItem {
  url: string;
  urlHash: string;
  baselineStatus?: number | null;
  targetStatus?: number | null;
  baselineTitle?: string | null;
  targetTitle?: string | null;
  baselineCanonical?: string | null;
  targetCanonical?: string | null;
  baselineRobots?: string | null;
  targetRobots?: string | null;
  isNewlyBroken: boolean;
  gscClicks?: number | null;
  gscImpressions?: number | null;
}

interface RuleSummaryItem {
  rule: string;
  severity: string;
  newCount: number;
  resolvedCount: number;
  netChange: number;
}

interface PageFields {
  status: number | null;
  title: string | null;
  metaDescription: string | null;
  h1: string | null;
  canonical: string | null;
  metaRobots: string | null;
  redirectTarget: string | null;
  wordCount: number | null;
}

interface IssueSampleItem {
  url: string;
  rule: string;
  severity: string;
  detail?: string | null;
  gscClicks?: number | null;
  before?: PageFields | null;
  after?: PageFields | null;
  changedFields?: (keyof PageFields)[];
}

interface DrillDownPage {
  data: IssueSampleItem[];
  total: number;
  page: number;
  perPage: number;
  lastPage: number;
}

interface ShareLink {
  id: string;
  title: string | null;
  shareUrl: string;
  viewCount: number;
  createdAt: string | null;
  expiresAt: string | null;
}

const FIELD_LABELS: Record<keyof PageFields, string> = {
  status: 'Status',
  title: 'Title',
  metaDescription: 'Meta description',
  h1: 'H1',
  canonical: 'Canonical',
  metaRobots: 'Robots',
  redirectTarget: 'Redirect',
  wordCount: 'Words',
};

const showValue = (v: string | number | null | undefined) =>
  v === null || v === undefined || v === '' ? '(none)' : String(v);

/** What changed on the issue's page between the two crawls. */
function PageChanges({ issue }: { issue: IssueSampleItem }) {
  if (issue.before === undefined && issue.after === undefined) return null;
  if (!issue.before && issue.after) {
    return <div className="mt-1 text-[10px] text-sky-300">New page in this crawl</div>;
  }
  if (issue.before && !issue.after) {
    return <div className="mt-1 text-[10px] text-slate-500">Page not in this crawl</div>;
  }
  const fields = issue.changedFields ?? [];
  if (fields.length === 0) {
    return <div className="mt-1 text-[10px] text-slate-500">Page fields unchanged</div>;
  }
  return (
    <ul className="mt-1 space-y-0.5 text-[10px]">
      {fields.map((f) => (
        <li key={f} className="flex flex-wrap items-center gap-1">
          <span className="text-slate-500">{FIELD_LABELS[f]}:</span>
          <span className="text-rose-300/80 line-through break-all">{showValue(issue.before?.[f])}</span>
          <ArrowRight className="h-2.5 w-2.5 text-slate-500" />
          <span className="text-emerald-300 break-all">{showValue(issue.after?.[f])}</span>
        </li>
      ))}
    </ul>
  );
}

interface UrlSampleItem {
  url: string;
  status?: number | null;
  title?: string | null;
  depth?: number;
}

interface DiffData {
  // "not_measured" when either side is an imported snapshot.
  issueMeasurement?: 'measured' | 'not_measured';
  summary: {
    baseline: RunSummary;
    target: RunSummary;
    delta: DeltaSummary;
  };
  newlyBroken: TransitionItem[];
  transitions: TransitionItem[];
  issueDeltas: {
    rules: RuleSummaryItem[];
    newIssues: IssueSampleItem[];
    resolvedIssues: IssueSampleItem[];
  };
  urlSamples: {
    newUrls: UrlSampleItem[];
    removedUrls: UrlSampleItem[];
  };
}

interface CompareProps {
  workspace: {
    id?: string;
    name: string;
    slug?: string;
  };
  project: {
    id?: string;
    name: string;
    domain: string;
  };
  availableRuns: CrawlRunOption[];
  selectedBaselineId?: string | null;
  selectedTargetId?: string | null;
  diff?: DiffData | null;
  isReadOnly?: boolean;
  reportTitle?: string | null;
  expiresAt?: string | null;
  shareToken?: string | null;
  canManageShares?: boolean;
  shareLinks?: ShareLink[];
  shareLinksNextCursor?: string | null;
}

export default function Compare({
  workspace,
  project,
  availableRuns = [],
  selectedBaselineId,
  selectedTargetId,
  diff,
  isReadOnly = false,
  reportTitle,
  expiresAt,
  shareToken,
  canManageShares = false,
  shareLinks = [],
  shareLinksNextCursor = null,
}: CompareProps) {
  const [baselineId, setBaselineId] = useState<string>(selectedBaselineId || '');
  const [targetId, setTargetId] = useState<string>(selectedTargetId || '');
  const [activeTab, setActiveTab] = useState<'broken' | 'issues' | 'transitions' | 'urls'>(
    diff && diff.newlyBroken.length > 0
      ? 'broken'
      : diff?.issueMeasurement === 'not_measured'
        ? 'transitions'
        : 'issues'
  );
  const [issueSubFilter, setIssueSubFilter] = useState<'all' | 'new' | 'resolved'>('all');
  const [searchFilter, setSearchFilter] = useState('');
  const [shareModalOpen, setShareModalOpen] = useState(false);
  const { has, billingUrl } = usePlan();
  const canShare = has('white-label-reports');
  const [shareExpiresInDays, setShareExpiresInDays] = useState<number>(30);
  const [shareTitle, setShareTitle] = useState('');
  const [shareLoading, setShareLoading] = useState(false);
  const [generatedShareUrl, setGeneratedShareUrl] = useState<string | null>(null);
  const [copied, setCopied] = useState(false);
  const [links, setLinks] = useState<ShareLink[]>(shareLinks);
  const [linksCursor, setLinksCursor] = useState<string | null>(shareLinksNextCursor);
  const [linksLoading, setLinksLoading] = useState(false);
  // An imported snapshot has no issue data, so its zero issue counts are not
  // a measured result. URL/status/metadata diffs stay meaningful.
  const issueDeltasMeasured = diff ? diff.issueMeasurement !== 'not_measured' : true;
  const selectedBaselineRun = availableRuns.find((run) => run.id === baselineId) ?? null;
  // Only the latest drill-down request may update the modal.
  const drillRequest = useRef<AbortController | null>(null);
  const [drillRule, setDrillRule] = useState<{ rule: string; severity: string; type: 'new' | 'resolved' } | null>(null);
  const [drillPage, setDrillPage] = useState<DrillDownPage | null>(null);
  const [drillLoading, setDrillLoading] = useState(false);

  const csrf = () => (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';

  const closeDrillDown = () => {
    drillRequest.current?.abort();
    drillRequest.current = null;
    setDrillRule(null);
    setDrillPage(null);
    setDrillLoading(false);
  };

  const loadDrillDown = async (rule: string, severity: string, type: 'new' | 'resolved', page = 1) => {
    drillRequest.current?.abort();
    const controller = new AbortController();
    drillRequest.current = controller;
    setDrillRule({ rule, severity, type });
    setDrillLoading(true);
    const params = new URLSearchParams({ type, rule, severity, page: String(page) });
    let url: string;
    if (isReadOnly && shareToken) {
      url = `/reports/crawls/diff/${shareToken}/issues?${params}`;
    } else {
      params.set('baseline', selectedBaselineId || '');
      params.set('target', selectedTargetId || '');
      url = `/workspaces/${workspace.id}/projects/${project.id}/crawls/compare/issues?${params}`;
    }
    try {
      const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal: controller.signal,
      });
      const data = response.ok ? await response.json() : null;
      if (drillRequest.current === controller) {
        setDrillPage(data);
      }
    } catch (error) {
      if (!(error instanceof DOMException && error.name === 'AbortError')) {
        throw error;
      }
    } finally {
      if (drillRequest.current === controller) {
        drillRequest.current = null;
        setDrillLoading(false);
      }
    }
  };

  const loadOlderLinks = async () => {
    if (!linksCursor) return;
    setLinksLoading(true);
    try {
      const response = await fetch(
        `/workspaces/${workspace.id}/projects/${project.id}/crawls/compare/shares?after=${encodeURIComponent(linksCursor)}`,
        { headers: { Accept: 'application/json' }, credentials: 'same-origin' }
      );
      if (response.ok) {
        const data: { shareLinks: ShareLink[]; shareLinksNextCursor: string | null } = await response.json();
        setLinks((current) => [...current, ...data.shareLinks.filter((l) => !current.some((c) => c.id === l.id))]);
        setLinksCursor(data.shareLinksNextCursor);
      }
    } finally {
      setLinksLoading(false);
    }
  };

  // Revoking every loaded link must not strand older ones: fetch the
  // next page as soon as the loaded list is empty.
  useEffect(() => {
    if (shareModalOpen && links.length === 0 && linksCursor && !linksLoading) {
      void loadOlderLinks();
    }
  }, [shareModalOpen, links.length, linksCursor]);

  const handleRevoke = async (link: ShareLink) => {
    const response = await fetch(
      `/workspaces/${workspace.id}/projects/${project.id}/crawls/compare/shares/${link.id}`,
      { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' }, credentials: 'same-origin' }
    );
    if (response.ok) {
      setLinks((current) => current.filter((l) => l.id !== link.id));
    }
  };

  const handleRunComparison = () => {
    if (!baselineId || !targetId || baselineId === targetId) return;
    router.get(
      `/workspaces/${workspace.id}/projects/${project.id}/crawls/compare`,
      { baseline: baselineId, target: targetId },
      { preserveState: true }
    );
  };

  const handleCreateShareLink = async () => {
    if (!baselineId || !targetId) return;
    setShareLoading(true);
    try {
      const response = await fetch(
        `/workspaces/${workspace.id}/projects/${project.id}/crawls/compare/share`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
            Accept: 'application/json',
          },
          body: JSON.stringify({
            baseline_id: baselineId,
            target_id: targetId,
            title: shareTitle || undefined,
            expires_in_days: shareExpiresInDays,
          }),
        }
      );
      if (response.ok) {
        const data = await response.json();
        setGeneratedShareUrl(data.shareUrl);
        setLinks((current) => [
          {
            id: data.id,
            title: shareTitle || null,
            shareUrl: data.shareUrl,
            viewCount: 0,
            createdAt: new Date().toISOString(),
            expiresAt: data.expiresAt,
          },
          ...current,
        ]);
      }
    } finally {
      setShareLoading(false);
    }
  };

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  const displayIssues: (IssueSampleItem & { deltaType: 'new' | 'resolved' })[] = diff
    ? [
        ...(issueSubFilter === 'all' || issueSubFilter === 'new'
          ? diff.issueDeltas.newIssues.map((iss) => ({ ...iss, deltaType: 'new' as const }))
          : []),
        ...(issueSubFilter === 'all' || issueSubFilter === 'resolved'
          ? diff.issueDeltas.resolvedIssues.map((iss) => ({ ...iss, deltaType: 'resolved' as const }))
          : []),
      ]
    : [];

  const filteredIssues = displayIssues.filter(
    (iss) =>
      !searchFilter ||
      iss.url.toLowerCase().includes(searchFilter.toLowerCase()) ||
      iss.rule.toLowerCase().includes(searchFilter.toLowerCase())
  );

  const formatSeverity = (sev: string) => {
    switch (sev) {
      case 'error':
        return <span className="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-rose-500/15 text-rose-400 border border-rose-500/30">Error</span>;
      case 'warning':
        return <span className="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-amber-500/15 text-amber-400 border border-amber-500/30">Warning</span>;
      default:
        return <span className="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-sky-500/15 text-sky-400 border border-sky-500/30">Opportunity</span>;
    }
  };

  const content = (
    <div className="space-y-6">
      {/* Top Banner & Header */}
      <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
          {!isReadOnly && workspace.id && (
            <div className="flex items-center gap-2 text-xs text-slate-400 mb-1">
              <Link href={`/workspaces/${workspace.id}`} className="hover:text-white transition">
                {workspace.name}
              </Link>
              <span>/</span>
              <Link href={`/workspaces/${workspace.id}/projects/${project.id}`} className="hover:text-white transition">
                {project.name}
              </Link>
              <span>/</span>
              <span className="text-slate-200">Crawl Comparison</span>
            </div>
          )}

          <div className="flex items-center gap-3">
            <h1 className="text-xl font-bold text-white tracking-tight">
              {reportTitle || 'Historical Crawl Diff'}
            </h1>
            {isReadOnly ? (
              <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                <Globe className="h-3 w-3" />
                <span>Shared Audit Diff</span>
              </span>
            ) : (
              <span className="text-xs font-mono text-slate-400 bg-slate-900 px-2 py-1 rounded border border-slate-800">
                {project.domain}
              </span>
            )}
          </div>
          {expiresAt && (
            <p className="text-xs text-amber-400/90 mt-1 flex items-center gap-1">
              <Clock className="h-3 w-3" />
              <span>Report expires on {new Date(expiresAt).toLocaleDateString()}</span>
            </p>
          )}
        </div>

        {!isReadOnly && canManageShares && !canShare && (
          <div className="flex items-center gap-2">
            {/* Client share links are a white-label reports feature. */}
            <Link
              href={billingUrl}
              title="Upgrade your plan to share white-label client reports"
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-700 bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition cursor-pointer opacity-75"
            >
              <Share2 className="h-3.5 w-3.5" />
              <span>Share Report</span>
              <UpgradePill />
            </Link>
            {/* Existing links stay revocable after a downgrade. */}
            {(links.length > 0 || linksCursor) && (
              <button
                type="button"
                onClick={() => setShareModalOpen(true)}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-700 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer"
              >
                <Share2 className="h-3.5 w-3.5" />
                <span>Manage links ({links.length}{linksCursor ? '+' : ''})</span>
              </button>
            )}
          </div>
        )}

        {!isReadOnly && canManageShares && canShare && (
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => setShareModalOpen(true)}
              disabled={!diff}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-slate-700 bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer disabled:opacity-50"
            >
              <Share2 className="h-3.5 w-3.5" />
              <span>Share Report</span>
            </button>
          </div>
        )}
      </div>

      {/* Issue measurement notice */}
      {diff && !issueDeltasMeasured && (
        <div className="flex items-start gap-2.5 rounded-xl border border-amber-500/30 bg-amber-500/5 px-4 py-3">
          <AlertTriangle className="h-4 w-4 shrink-0 text-amber-400 mt-0.5" />
          <div className="text-xs">
            <p className="font-semibold text-amber-200">Issue deltas not measured</p>
            <p className="mt-0.5 text-amber-200/80">
              At least one side of this comparison is an imported snapshot with no issue data, so
              new, resolved and persistent issue counts are unavailable. URL, status, metadata and
              redirect diffs are still shown.
            </p>
          </div>
        </div>
      )}

      {/* Crawl Run Selector Bar (Authenticated View) */}
      {!isReadOnly && (
        <div className="p-4 rounded-xl border border-slate-800 bg-slate-900/60 backdrop-blur-sm">
          <div className="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            <div className="md:col-span-5">
              <label className="flex items-center gap-2 text-xs font-semibold text-slate-300 mb-1.5">
                <span>Baseline Crawl (Earlier Snapshot)</span>
                {selectedBaselineRun?.source === 'import' && (
                  <span className="px-1.5 py-0.5 rounded text-[10px] font-medium bg-violet-500/10 text-violet-300 border border-violet-500/30">
                    Imported
                  </span>
                )}
              </label>
              <select
                value={baselineId}
                onChange={(e) => setBaselineId(e.target.value)}
                className="w-full rounded-lg bg-slate-950 border border-slate-800 text-xs text-white p-2.5 focus:outline-none focus:border-emerald-500 transition"
              >
                <option value="">Select baseline crawl...</option>
                {availableRuns.map((run) => (
                  <option key={run.id} value={run.id}>
                    {run.label}
                  </option>
                ))}
              </select>
            </div>

            <div className="md:col-span-1 flex items-center justify-center pb-2">
              <ArrowRight className="h-4 w-4 text-slate-500 hidden md:block" />
              <ArrowDown className="h-4 w-4 text-slate-500 md:hidden" />
            </div>

            <div className="md:col-span-5">
              <label className="block text-xs font-semibold text-slate-300 mb-1.5">
                Target Crawl (Later Snapshot)
              </label>
              <select
                value={targetId}
                onChange={(e) => setTargetId(e.target.value)}
                className="w-full rounded-lg bg-slate-950 border border-slate-800 text-xs text-white p-2.5 focus:outline-none focus:border-emerald-500 transition"
              >
                <option value="">Select target crawl...</option>
                {availableRuns.map((run) => (
                  <option key={run.id} value={run.id}>
                    {run.label}
                  </option>
                ))}
              </select>
            </div>

            <div className="md:col-span-1">
              <button
                type="button"
                onClick={handleRunComparison}
                disabled={!baselineId || !targetId || baselineId === targetId}
                className="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-xs font-semibold transition cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed"
                title="Compute crawl snapshot diff"
              >
                <RefreshCw className="h-3.5 w-3.5" />
                <span>Diff</span>
              </button>
            </div>
          </div>
          {baselineId && targetId && baselineId === targetId && (
            <p className="mt-2 text-xs text-amber-400">
              Please select two different crawl snapshots to compare.
            </p>
          )}
        </div>
      )}

      {/* Comparison Body */}
      {!diff ? (
        <div className="py-20 text-center rounded-2xl border border-slate-800 bg-slate-900/30">
          <Layers className="h-10 w-10 text-slate-600 mx-auto mb-3" />
          <h3 className="text-base font-semibold text-white">No crawl comparison selected</h3>
          <p className="mt-1 text-xs text-slate-400 max-w-md mx-auto">
            {availableRuns.length < 2
              ? 'This project needs at least two completed crawl runs to compute technical diffs. Run crawls via Coqui Desktop or the cloud crawler.'
              : 'Choose a baseline and target crawl above and click Diff to analyze newly broken URLs, status transitions, and issue deltas.'}
          </p>
        </div>
      ) : (
        <div className="space-y-6">
          {/* KPI Metrics Summary Grid */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
            {/* Total URLs Delta */}
            <div className="p-4 rounded-xl border border-slate-800 bg-slate-900/40">
              <div className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                Total URLs
              </div>
              <div className="mt-1.5 flex items-baseline gap-2">
                <span className="text-2xl font-bold font-mono text-white">
                  {diff.summary.target.totalUrls.toLocaleString()}
                </span>
                <span
                  className={`inline-flex items-center text-xs font-mono font-semibold ${
                    diff.summary.delta.totalUrls >= 0 ? 'text-emerald-400' : 'text-rose-400'
                  }`}
                >
                  {diff.summary.delta.totalUrls >= 0 ? `+${diff.summary.delta.totalUrls}` : diff.summary.delta.totalUrls}
                </span>
              </div>
              <div className="mt-1 flex items-center gap-1.5 text-[11px] text-slate-500 font-mono">
                <span>Baseline: {diff.summary.baseline.totalUrls.toLocaleString()}</span>
                {diff.summary.baseline.source === 'import' && (
                  <span className="px-1 py-0.5 rounded text-[9px] font-sans font-medium bg-violet-500/10 text-violet-300 border border-violet-500/30">
                    Imported
                  </span>
                )}
              </div>
            </div>

            {/* Newly Broken URLs */}
            <div className="p-4 rounded-xl border border-slate-800 bg-slate-900/40">
              <div className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider flex items-center justify-between">
                <span>Newly Broken URLs</span>
                {diff.summary.delta.newlyBrokenCount > 0 && (
                  <AlertCircle className="h-3.5 w-3.5 text-rose-400" />
                )}
              </div>
              <div className="mt-1.5 flex items-baseline gap-2">
                <span
                  className={`text-2xl font-bold font-mono ${
                    diff.summary.delta.newlyBrokenCount > 0 ? 'text-rose-400' : 'text-white'
                  }`}
                >
                  {diff.summary.delta.newlyBrokenCount}
                </span>
                <span className="text-xs text-slate-400">URLs (4xx / 5xx)</span>
              </div>
              {diff.summary.delta.totalLostClicks > 0 ? (
                <div className="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-blue-400">
                  <Flame className="h-3 w-3 text-orange-400" />
                  <span>~{diff.summary.delta.totalLostClicks.toLocaleString()} Google clicks at risk</span>
                </div>
              ) : (
                <div className="mt-1 text-[11px] text-slate-500">
                  0 search traffic impacted
                </div>
              )}
            </div>

            {/* Status Transitions */}
            <div className="p-4 rounded-xl border border-slate-800 bg-slate-900/40">
              <div className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                Status Transitions
              </div>
              <div className="mt-1.5 flex items-baseline gap-2">
                <span className="text-2xl font-bold font-mono text-white">
                  {diff.summary.delta.statusChangesCount}
                </span>
                <span className="text-xs text-slate-400">code changes</span>
              </div>
              <div className="mt-1 text-[11px] text-slate-500 font-mono">
                +{diff.summary.delta.newUrlsCount} new / -{diff.summary.delta.removedUrlsCount} removed
              </div>
            </div>

            {/* Issue Delta Health */}
            <div className="p-4 rounded-xl border border-slate-800 bg-slate-900/40">
              <div className="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                Issue Health Delta
              </div>
              {issueDeltasMeasured ? (
                <>
                  <div className="mt-1.5 flex items-baseline gap-2">
                    <span className="text-sm font-bold font-mono text-rose-400">
                      +{diff.summary.delta.newIssuesCount}
                    </span>
                    <span className="text-xs text-slate-500">/</span>
                    <span className="text-sm font-bold font-mono text-emerald-400">
                      -{diff.summary.delta.resolvedIssuesCount}
                    </span>
                    <span className="text-xs text-slate-400">resolved</span>
                  </div>
                  <div className="mt-1 text-[11px] text-slate-500 font-mono">
                    {diff.summary.delta.persistentIssuesCount} persistent issues
                  </div>
                </>
              ) : (
                <>
                  <div className="mt-1.5 text-sm font-semibold text-amber-300">Not measured</div>
                  <div className="mt-1 text-[11px] text-slate-500">
                    Imported snapshot has no issue data
                  </div>
                </>
              )}
            </div>
          </div>

          {/* Navigation Tabs */}
          <div className="flex items-center justify-between border-b border-slate-800 pb-2">
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={() => setActiveTab('broken')}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer flex items-center gap-1.5 ${
                  activeTab === 'broken'
                    ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40'
                    : 'text-slate-400 hover:text-white'
                }`}
              >
                <AlertCircle className="h-3.5 w-3.5" />
                <span>Newly Broken URLs</span>
                <span className="ml-1 px-1.5 py-0.2 rounded-full text-[10px] bg-rose-500/30 text-rose-200">
                  {diff.newlyBroken.length}
                </span>
              </button>

              <button
                type="button"
                onClick={() => setActiveTab('issues')}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer flex items-center gap-1.5 ${
                  activeTab === 'issues'
                    ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40'
                    : 'text-slate-400 hover:text-white'
                }`}
              >
                <Layers className="h-3.5 w-3.5" />
                <span>Issue Deltas</span>
                <span className="ml-1 px-1.5 py-0.2 rounded-full text-[10px] bg-slate-800 text-slate-300">
                  {issueDeltasMeasured ? diff.issueDeltas.rules.length : '—'}
                </span>
              </button>

              <button
                type="button"
                onClick={() => setActiveTab('transitions')}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer flex items-center gap-1.5 ${
                  activeTab === 'transitions'
                    ? 'bg-blue-500/20 text-blue-300 border border-blue-500/40'
                    : 'text-slate-400 hover:text-white'
                }`}
              >
                <Sliders className="h-3.5 w-3.5" />
                <span>Technical Transitions</span>
                <span className="ml-1 px-1.5 py-0.2 rounded-full text-[10px] bg-slate-800 text-slate-300">
                  {diff.transitions.length}
                </span>
              </button>

              <button
                type="button"
                onClick={() => setActiveTab('urls')}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition cursor-pointer flex items-center gap-1.5 ${
                  activeTab === 'urls'
                    ? 'bg-purple-500/20 text-purple-300 border border-purple-500/40'
                    : 'text-slate-400 hover:text-white'
                }`}
              >
                <Globe className="h-3.5 w-3.5" />
                <span>URL Samples (+ / -)</span>
              </button>
            </div>

            {/* Filter Input */}
            <div className="relative w-64">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-slate-500" />
              <input
                type="text"
                value={searchFilter}
                onChange={(e) => setSearchFilter(e.target.value)}
                placeholder="Filter by URL or rule..."
                className="w-full pl-8 pr-3 py-1 rounded-lg bg-slate-950 border border-slate-800 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500"
              />
            </div>
          </div>

          {/* TAB 1: Newly Broken URLs */}
          {activeTab === 'broken' && (
            <div className="rounded-xl border border-slate-800 bg-slate-900/40 overflow-hidden">
              {diff.newlyBroken.length === 0 ? (
                <div className="py-12 text-center">
                  <CheckCircle2 className="h-8 w-8 text-emerald-400 mx-auto mb-2" />
                  <h4 className="text-sm font-semibold text-white">No newly broken URLs detected</h4>
                  <p className="text-xs text-slate-400 mt-1">
                    None of the active pages in the baseline snapshot transitioned to 4xx/5xx error status.
                  </p>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-left text-xs border-collapse">
                    <thead>
                      <tr className="border-b border-slate-800 bg-slate-950/60 text-slate-400">
                        <th className="py-2.5 px-4 font-medium">Affected Page URL</th>
                        <th className="py-2.5 px-3 font-medium text-center">Baseline</th>
                        <th className="py-2.5 px-3 font-medium text-center">Target (Now)</th>
                        <th className="py-2.5 px-3 font-medium text-right text-blue-300">GSC Clicks (28d)</th>
                        <th className="py-2.5 px-3 font-medium text-right text-blue-300">GSC Impr. (28d)</th>
                        <th className="py-2.5 px-4 font-medium text-right">Actions</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800/60">
                      {diff.newlyBroken
                        .filter((item) => !searchFilter || item.url.toLowerCase().includes(searchFilter.toLowerCase()))
                        .map((item) => (
                          <tr key={item.urlHash} className="hover:bg-slate-800/30 transition text-slate-300">
                            <td className="py-3 px-4 font-mono text-white max-w-md truncate">
                              <div className="flex items-center gap-1.5">
                                <span className="truncate" title={item.url}>
                                  {item.url}
                                </span>
                              </div>
                            </td>
                            <td className="py-3 px-3 text-center font-mono">
                              <span className="px-1.5 py-0.5 rounded text-[11px] font-bold bg-slate-800 text-slate-300 border border-slate-700">
                                {item.baselineStatus ?? 'None'}
                              </span>
                            </td>
                            <td className="py-3 px-3 text-center font-mono">
                              <span className="px-1.5 py-0.5 rounded text-[11px] font-bold bg-rose-500/20 text-rose-300 border border-rose-500/40">
                                {item.targetStatus}
                              </span>
                            </td>
                            <td className="py-3 px-3 text-right font-mono text-slate-200">
                              {item.gscClicks !== null && item.gscClicks !== undefined && item.gscClicks > 0 ? (
                                <span className="inline-flex items-center gap-1 font-bold text-blue-400">
                                  <Flame className="h-3 w-3 text-orange-400" />
                                  <span>{item.gscClicks.toLocaleString()}</span>
                                </span>
                              ) : (
                                <span className="text-slate-600">—</span>
                              )}
                            </td>
                            <td className="py-3 px-3 text-right font-mono text-slate-400">
                              {item.gscImpressions !== null && item.gscImpressions !== undefined && item.gscImpressions > 0 ? (
                                item.gscImpressions.toLocaleString()
                              ) : (
                                <span className="text-slate-600">—</span>
                              )}
                            </td>
                            <td className="py-3 px-4 text-right">
                              <a
                                href={item.url}
                                target="_blank"
                                rel="noreferrer"
                                className="text-slate-400 hover:text-white transition"
                                title="Open URL in browser"
                              >
                                <ExternalLink className="h-3.5 w-3.5 inline" />
                              </a>
                            </td>
                          </tr>
                        ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}

          {/* TAB 2: Issue Deltas */}
          {activeTab === 'issues' && (
            !issueDeltasMeasured ? (
              <div className="py-12 text-center rounded-xl border border-slate-800 bg-slate-900/40">
                <AlertTriangle className="h-8 w-8 text-amber-400 mx-auto mb-2" />
                <h4 className="text-sm font-semibold text-white">Issue deltas not measured</h4>
                <p className="mt-1 text-xs text-slate-400 max-w-md mx-auto">
                  An imported snapshot does not include issue data, so new, resolved and persistent
                  issues cannot be compared for this pair of runs.
                </p>
              </div>
            ) : (
            <div className="space-y-4">
              {/* Rules summary cards */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                {diff.issueDeltas.rules.map((rule) => (
                  <div
                    key={rule.rule}
                    className="p-3 rounded-lg border border-slate-800 bg-slate-900/50 flex items-center justify-between"
                  >
                    <div>
                      <div className="flex items-center gap-1.5">
                        {formatSeverity(rule.severity)}
                        <span className="text-xs font-mono font-medium text-white truncate max-w-[160px]" title={rule.rule}>
                          {rule.rule}
                        </span>
                      </div>
                      <div className="mt-1 text-[11px] text-slate-400 flex items-center gap-2">
                        {rule.newCount > 0 ? (
                          <button
                            type="button"
                            onClick={() => loadDrillDown(rule.rule, rule.severity, 'new')}
                            className="text-rose-300 hover:text-rose-200 underline-offset-2 hover:underline cursor-pointer"
                          >
                            +{rule.newCount} new
                          </button>
                        ) : (
                          <span>+0 new</span>
                        )}
                        {rule.resolvedCount > 0 ? (
                          <button
                            type="button"
                            onClick={() => loadDrillDown(rule.rule, rule.severity, 'resolved')}
                            className="text-emerald-300 hover:text-emerald-200 underline-offset-2 hover:underline cursor-pointer"
                          >
                            -{rule.resolvedCount} resolved
                          </button>
                        ) : (
                          <span>-0 resolved</span>
                        )}
                      </div>
                    </div>
                    <div
                      className={`text-sm font-mono font-bold ${
                        rule.netChange > 0
                          ? 'text-rose-400'
                          : rule.netChange < 0
                          ? 'text-emerald-400'
                          : 'text-slate-400'
                      }`}
                    >
                      {rule.netChange > 0 ? `+${rule.netChange}` : rule.netChange}
                    </div>
                  </div>
                ))}
              </div>

              {/* Sub-filter toggle */}
              <div className="flex items-center gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setIssueSubFilter('all')}
                  className={`px-2.5 py-1 rounded text-xs font-medium transition cursor-pointer ${
                    issueSubFilter === 'all'
                      ? 'bg-slate-800 text-white'
                      : 'text-slate-400 hover:text-white'
                  }`}
                >
                  All Sample Issues
                </button>
                <button
                  type="button"
                  onClick={() => setIssueSubFilter('new')}
                  className={`px-2.5 py-1 rounded text-xs font-medium transition cursor-pointer ${
                    issueSubFilter === 'new'
                      ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30'
                      : 'text-slate-400 hover:text-white'
                  }`}
                >
                  New Issues (+{diff.issueDeltas.newIssues.length})
                </button>
                <button
                  type="button"
                  onClick={() => setIssueSubFilter('resolved')}
                  className={`px-2.5 py-1 rounded text-xs font-medium transition cursor-pointer ${
                    issueSubFilter === 'resolved'
                      ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                      : 'text-slate-400 hover:text-white'
                  }`}
                >
                  Resolved Issues (-{diff.issueDeltas.resolvedIssues.length})
                </button>
              </div>

              {/* Sample issues table */}
              <div className="rounded-xl border border-slate-800 bg-slate-900/40 overflow-hidden">
                <div className="overflow-x-auto">
                  <table className="w-full text-left text-xs border-collapse">
                    <thead>
                      <tr className="border-b border-slate-800 bg-slate-950/60 text-slate-400">
                        <th className="py-2.5 px-4 font-medium">Type</th>
                        <th className="py-2.5 px-3 font-medium">Rule & Severity</th>
                        <th className="py-2.5 px-4 font-medium">Affected Page URL</th>
                        <th className="py-2.5 px-4 font-medium">Issue Details</th>
                        <th className="py-2.5 px-3 font-medium text-right text-blue-300">GSC Clicks</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800/60">
                      {filteredIssues.map((iss, idx) => (
                        <tr key={`${iss.url}-${iss.rule}-${idx}`} className="hover:bg-slate-800/30 transition text-slate-300">
                            <td className="py-3 px-4">
                              {iss.deltaType === 'new' ? (
                                <span className="inline-flex items-center gap-0.5 text-rose-400 font-semibold font-mono text-[11px]">
                                  <Plus className="h-3 w-3" />
                                  <span>NEW</span>
                                </span>
                              ) : (
                                <span className="inline-flex items-center gap-0.5 text-emerald-400 font-semibold font-mono text-[11px]">
                                  <Check className="h-3 w-3" />
                                  <span>RESOLVED</span>
                                </span>
                              )}
                            </td>
                            <td className="py-3 px-3">
                              <div className="flex items-center gap-1.5">
                                {formatSeverity(iss.severity)}
                                <span className="font-mono text-slate-200">{iss.rule}</span>
                              </div>
                            </td>
                            <td className="py-3 px-4 font-mono text-white max-w-xs" title={iss.url}>
                              <div className="truncate">{iss.url}</div>
                              <PageChanges issue={iss} />
                            </td>
                            <td className="py-3 px-4 text-slate-400 max-w-sm truncate" title={iss.detail || ''}>
                              {iss.detail || '—'}
                            </td>
                            <td className="py-3 px-3 text-right font-mono text-slate-300">
                              {iss.gscClicks !== null && iss.gscClicks !== undefined && iss.gscClicks > 0 ? (
                                <span className="text-blue-400 font-bold">{iss.gscClicks.toLocaleString()}</span>
                              ) : (
                                <span className="text-slate-600">—</span>
                              )}
                            </td>
                          </tr>
                        ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
            )
          )}

          {/* TAB 3: Technical Transitions */}
          {activeTab === 'transitions' && (
            <div className="rounded-xl border border-slate-800 bg-slate-900/40 overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-xs border-collapse">
                  <thead>
                    <tr className="border-b border-slate-800 bg-slate-950/60 text-slate-400">
                      <th className="py-2.5 px-4 font-medium">Page URL</th>
                      <th className="py-2.5 px-3 font-medium text-center">Status</th>
                      <th className="py-2.5 px-4 font-medium">Title Transition</th>
                      <th className="py-2.5 px-4 font-medium">Canonical Transition</th>
                      <th className="py-2.5 px-3 font-medium">Robots</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60">
                    {diff.transitions
                      .filter((t) => !searchFilter || t.url.toLowerCase().includes(searchFilter.toLowerCase()))
                      .map((t) => (
                        <tr key={t.urlHash} className="hover:bg-slate-800/30 transition text-slate-300">
                          <td className="py-3 px-4 font-mono text-white max-w-xs truncate" title={t.url}>
                            {t.url}
                          </td>
                          <td className="py-3 px-3 text-center font-mono">
                            <span className="text-slate-400">{t.baselineStatus ?? '—'}</span>
                            <span className="mx-1 text-slate-600">→</span>
                            <span className="text-emerald-400 font-semibold">{t.targetStatus ?? '—'}</span>
                          </td>
                          <td className="py-3 px-4 max-w-xs truncate">
                            {t.baselineTitle !== t.targetTitle ? (
                              <div>
                                <div className="text-slate-500 line-through truncate text-[11px]">{t.baselineTitle || '(empty)'}</div>
                                <div className="text-slate-200 truncate">{t.targetTitle || '(empty)'}</div>
                              </div>
                            ) : (
                              <span className="text-slate-500">—</span>
                            )}
                          </td>
                          <td className="py-3 px-4 max-w-xs truncate">
                            {t.baselineCanonical !== t.targetCanonical ? (
                              <div>
                                <div className="text-slate-500 line-through truncate text-[11px]">{t.baselineCanonical || '(none)'}</div>
                                <div className="text-slate-200 truncate">{t.targetCanonical || '(none)'}</div>
                              </div>
                            ) : (
                              <span className="text-slate-500">—</span>
                            )}
                          </td>
                          <td className="py-3 px-3 font-mono text-[11px]">
                            {t.baselineRobots !== t.targetRobots ? (
                              <span className="text-amber-400">{t.targetRobots || 'default'}</span>
                            ) : (
                              <span className="text-slate-500">—</span>
                            )}
                          </td>
                        </tr>
                      ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* TAB 4: URL Samples */}
          {activeTab === 'urls' && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              {/* Discovered URLs */}
              <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4 space-y-3">
                <div className="flex items-center justify-between">
                  <h4 className="text-xs font-semibold text-white uppercase tracking-wider flex items-center gap-1.5">
                    <Plus className="h-3.5 w-3.5 text-emerald-400" />
                    <span>Discovered URLs in Target ({diff.summary.delta.newUrlsCount})</span>
                  </h4>
                  <span className="text-[11px] text-slate-500">Showing top 100</span>
                </div>
                <div className="divide-y divide-slate-800/60 max-h-96 overflow-y-auto pr-1">
                  {diff.urlSamples.newUrls.map((u, i) => (
                    <div key={i} className="py-2 flex items-center justify-between text-xs">
                      <span className="font-mono text-slate-300 truncate max-w-xs" title={u.url}>
                        {u.url}
                      </span>
                      <span className="font-mono text-[11px] text-emerald-400">
                        {u.status ?? '200'}
                      </span>
                    </div>
                  ))}
                </div>
              </div>

              {/* Removed URLs */}
              <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4 space-y-3">
                <div className="flex items-center justify-between">
                  <h4 className="text-xs font-semibold text-white uppercase tracking-wider flex items-center gap-1.5">
                    <Minus className="h-3.5 w-3.5 text-rose-400" />
                    <span>Removed URLs from Target ({diff.summary.delta.removedUrlsCount})</span>
                  </h4>
                  <span className="text-[11px] text-slate-500">Showing top 100</span>
                </div>
                <div className="divide-y divide-slate-800/60 max-h-96 overflow-y-auto pr-1">
                  {diff.urlSamples.removedUrls.map((u, i) => (
                    <div key={i} className="py-2 flex items-center justify-between text-xs">
                      <span className="font-mono text-slate-300 truncate max-w-xs" title={u.url}>
                        {u.url}
                      </span>
                      <span className="font-mono text-[11px] text-slate-500">
                        was {u.status ?? '200'}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Per-rule drill-down */}
      {drillRule && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
          <div className="w-full max-w-4xl rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-2xl space-y-4">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <h3 className="text-base font-semibold text-white flex items-center gap-2">
                {formatSeverity(drillRule.severity)}
                <span className="font-mono">{drillRule.rule}</span>
                <span className={drillRule.type === 'new' ? 'text-rose-300 text-xs' : 'text-emerald-300 text-xs'}>
                  {drillRule.type === 'new' ? 'New' : 'Resolved'}
                  {drillPage ? ` (${drillPage.total.toLocaleString()})` : ''}
                </span>
              </h3>
              <button
                type="button"
                onClick={closeDrillDown}
                className="text-slate-400 hover:text-white"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            {drillLoading && !drillPage ? (
              <div className="py-10 text-center text-xs text-slate-400">
                <RefreshCw className="h-5 w-5 animate-spin mx-auto mb-2 text-slate-500" />
                Loading affected URLs...
              </div>
            ) : !drillPage || drillPage.data.length === 0 ? (
              <div className="py-10 text-center text-xs text-slate-500">No affected URLs.</div>
            ) : (
              <div className={`max-h-[60vh] overflow-y-auto ${drillLoading ? 'opacity-60' : ''}`}>
                <table className="w-full text-left text-xs border-collapse">
                  <thead>
                    <tr className="border-b border-slate-800 text-slate-400">
                      <th className="py-2 px-3 font-medium">Page URL and what changed</th>
                      <th className="py-2 px-3 font-medium">Issue details</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-800/60">
                    {drillPage.data.map((iss) => (
                      <tr key={`${iss.url}-${iss.rule}`} className="text-slate-300 align-top">
                        <td className="py-2.5 px-3 font-mono text-white max-w-md">
                          <div className="truncate" title={iss.url}>{iss.url}</div>
                          <PageChanges issue={iss} />
                        </td>
                        <td className="py-2.5 px-3 text-slate-400 max-w-sm break-words">{iss.detail || '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {drillPage && drillPage.lastPage > 1 && (
              <div className="flex items-center justify-between pt-2 border-t border-slate-800 text-xs text-slate-400">
                <span>
                  Page {drillPage.page} of {drillPage.lastPage}
                </span>
                <div className="flex gap-2">
                  <button
                    type="button"
                    disabled={drillLoading || drillPage.page <= 1}
                    onClick={() => loadDrillDown(drillRule.rule, drillRule.severity, drillRule.type, drillPage.page - 1)}
                    className="px-3 py-1 rounded-lg border border-slate-700 bg-slate-800 hover:bg-slate-700 disabled:opacity-40 cursor-pointer"
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    disabled={drillLoading || drillPage.page >= drillPage.lastPage}
                    onClick={() => loadDrillDown(drillRule.rule, drillRule.severity, drillRule.type, drillPage.page + 1)}
                    className="px-3 py-1 rounded-lg border border-slate-700 bg-slate-800 hover:bg-slate-700 disabled:opacity-40 cursor-pointer"
                  >
                    Next
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Share Report Modal */}
      {shareModalOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
          <div className="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-2xl space-y-4">
            <div className="flex items-center justify-between border-b border-slate-800 pb-3">
              <h3 className="text-base font-semibold text-white flex items-center gap-2">
                <Share2 className="h-4 w-4 text-emerald-400" />
                <span>{canShare ? 'Create Shareable Diff Report' : 'Share Links'}</span>
              </h3>
              <button
                type="button"
                onClick={() => {
                  setShareModalOpen(false);
                  setGeneratedShareUrl(null);
                }}
                className="text-slate-400 hover:text-white"
              >
                <X className="h-4 w-4" />
              </button>
            </div>

            {!generatedShareUrl ? (
              <div className="space-y-4">
                {canShare ? (
                  <p className="text-xs text-slate-400">
                    Generate a secure, read-only public report link to share this technical crawl comparison with clients, stakeholders, or developers.
                  </p>
                ) : (
                  <p className="text-xs text-slate-400">
                    Your plan no longer includes new client share links. You can still revoke the links below.
                  </p>
                )}

                {canShare && (
                <>
                <div>
                  <label className="block text-xs font-medium text-slate-300 mb-1">
                    Custom Report Title (Optional)
                  </label>
                  <input
                    type="text"
                    value={shareTitle}
                    onChange={(e) => setShareTitle(e.target.value)}
                    placeholder={`Crawl Diff: ${project.name}`}
                    className="w-full rounded-lg bg-slate-950 border border-slate-800 text-xs text-white p-2.5 focus:outline-none focus:border-emerald-500"
                  />
                </div>

                <div>
                  <label className="block text-xs font-medium text-slate-300 mb-1">
                    Link Expiry
                  </label>
                  <select
                    value={shareExpiresInDays}
                    onChange={(e) => setShareExpiresInDays(Number(e.target.value))}
                    className="w-full rounded-lg bg-slate-950 border border-slate-800 text-xs text-white p-2.5 focus:outline-none focus:border-emerald-500"
                  >
                    <option value={7}>Expires in 7 days</option>
                    <option value={30}>Expires in 30 days (Default)</option>
                    <option value={90}>Expires in 90 days</option>
                  </select>
                </div>
                </>
                )}

                {(links.length > 0 || linksCursor) && (
                  <div className="pt-2 border-t border-slate-800">
                    <span className="block text-xs font-medium text-slate-300 mb-1.5">Active share links</span>
                    {links.length === 0 && linksLoading && (
                      <p className="text-[11px] text-slate-500">Loading older links…</p>
                    )}
                    <ul className="space-y-1.5 max-h-40 overflow-y-auto">
                      {links.map((link) => (
                        <li key={link.id} className="flex items-center justify-between gap-2 text-[11px]">
                          <div className="min-w-0">
                            <div className="truncate text-slate-200">{link.title || 'Untitled report'}</div>
                            <div className="text-slate-500">
                              {link.viewCount} views
                              {link.expiresAt && ` • expires ${new Date(link.expiresAt).toLocaleDateString()}`}
                            </div>
                          </div>
                          <button
                            type="button"
                            onClick={() => handleRevoke(link)}
                            className="inline-flex items-center gap-1 px-2 py-1 rounded border border-rose-500/30 text-rose-300 hover:bg-rose-500/10 cursor-pointer shrink-0"
                          >
                            <Trash2 className="h-3 w-3" />
                            <span>Revoke</span>
                          </button>
                        </li>
                      ))}
                    </ul>
                    {linksCursor && (
                      <button
                        type="button"
                        onClick={loadOlderLinks}
                        disabled={linksLoading}
                        className="mt-2 text-[11px] text-slate-400 hover:text-white disabled:opacity-50"
                      >
                        {linksLoading ? 'Loading…' : 'Show older links'}
                      </button>
                    )}
                  </div>
                )}

                <div className="pt-2 flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setShareModalOpen(false)}
                    className="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-400 hover:text-white"
                  >
                    {canShare ? 'Cancel' : 'Close'}
                  </button>
                  {canShare && (
                    <button
                      type="button"
                      onClick={handleCreateShareLink}
                      disabled={shareLoading}
                      className="px-4 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition cursor-pointer disabled:opacity-50"
                    >
                      {shareLoading ? 'Generating...' : 'Generate Link'}
                    </button>
                  )}
                </div>
              </div>
            ) : (
              <div className="space-y-4">
                <div className="p-3 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs flex items-center gap-2">
                  <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-400" />
                  <span>Report link generated successfully!</span>
                </div>

                <div className="flex items-center gap-2">
                  <input
                    type="text"
                    readOnly
                    value={generatedShareUrl}
                    className="w-full rounded-lg bg-slate-950 border border-slate-800 text-xs text-slate-300 p-2.5 font-mono select-all"
                  />
                  <button
                    type="button"
                    onClick={() => copyToClipboard(generatedShareUrl)}
                    className="px-3 py-2.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer flex items-center gap-1 shrink-0"
                  >
                    {copied ? <Check className="h-3.5 w-3.5 text-emerald-400" /> : <Copy className="h-3.5 w-3.5" />}
                    <span>{copied ? 'Copied' : 'Copy'}</span>
                  </button>
                </div>

                <div className="pt-2 flex justify-end">
                  <button
                    type="button"
                    onClick={() => {
                      setShareModalOpen(false);
                      setGeneratedShareUrl(null);
                    }}
                    className="px-4 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium"
                  >
                    Done
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );

  if (isReadOnly) {
    return (
      <div className="min-h-screen bg-slate-950 text-slate-100 p-6 md:p-12 max-w-7xl mx-auto">
        <Head title={reportTitle || `Crawl Diff — ${project.name}`} />
        {content}
      </div>
    );
  }

  return (
    <AppLayout>
      <Head title={`Crawl Compare — ${project.name}`} />
      {content}
    </AppLayout>
  );
}
