import { Head, Link, router, useForm } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowDown,
  ArrowLeft,
  ArrowUp,
  CheckCircle2,
  Clock,
  Copy,
  ExternalLink,
  FileSpreadsheet,
  FileText,
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
  Trash2,
  Upload,
  X,
} from 'lucide-react';
import { type FormEvent, useMemo, useState } from 'react';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';
import DataProviderNotice from '../../components/DataProviderNotice';

interface ProjectDetails {
  id: string;
  name: string;
  domain: string;
  canonicalUrl: string;
}

interface MonitoredBacklinkItem {
  id: string;
  source_url: string;
  target_url: string;
  expected_anchor: string | null;
  expected_rel: string | null;
  state: 'active' | 'lost' | 'rel_changed' | 'error' | string;
  last_http_status: number | null;
  last_observed_rel: string | null;
  last_page_noindex: boolean;
  last_verified_at: string | null;
  created_at: string | null;
}

interface WatchlistMetrics {
  total: number;
  active: number;
  lost: number;
  rel_changed: number;
  error: number;
  noindex: number;
}

interface WatchlistPageProps {
  workspace: WorkspaceSummary;
  project: ProjectDetails;
  links: MonitoredBacklinkItem[];
  metrics: WatchlistMetrics;
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

export default function BacklinkWatchlist({
  workspace,
  project,
  links = [],
  metrics,
}: WatchlistPageProps) {
  const [activeFilter, setActiveFilter] = useState<'all' | 'active' | 'lost' | 'rel_changed' | 'error'>('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [verifyingAll, setVerifyingAll] = useState(false);
  const [verifyingId, setVerifyingId] = useState<string | null>(null);
  const [importingDfs, setImportingDfs] = useState(false);
  const [addModalOpen, setAddModalOpen] = useState(false);
  const [csvModalOpen, setCsvModalOpen] = useState(false);
  const [copiedUrl, setCopiedUrl] = useState<string | null>(null);

  const addForm = useForm({
    source_url: '',
    target_url: project.canonicalUrl,
    expected_anchor: '',
    expected_rel: 'dofollow',
  });

  const csvForm = useForm<{ csv_file: File | null }>({
    csv_file: null,
  });

  const handleCopy = (url: string) => {
    navigator.clipboard.writeText(url);
    setCopiedUrl(url);
    setTimeout(() => setCopiedUrl(null), 2000);
  };

  const handleVerifyAll = () => {
    setVerifyingAll(true);
    router.post(
      `/workspaces/${workspace.id}/projects/${project.id}/backlinks/watchlist/verify`,
      {},
      {
        onFinish: () => setVerifyingAll(false),
      }
    );
  };

  const handleVerifySingle = (linkId: string) => {
    setVerifyingId(linkId);
    router.post(
      `/workspaces/${workspace.id}/projects/${project.id}/backlinks/watchlist/${linkId}/verify`,
      {},
      {
        onFinish: () => setVerifyingId(null),
      }
    );
  };

  const handleImportDfs = () => {
    if (confirm(`Import up to 100 high-value backlinks for ${project.domain} into active link-rot monitoring?`)) {
      setImportingDfs(true);
      router.post(
        `/workspaces/${workspace.id}/projects/${project.id}/backlinks/watchlist/import-dfs`,
        {},
        {
          onFinish: () => setImportingDfs(false),
        }
      );
    }
  };

  const handleDelete = (link: MonitoredBacklinkItem) => {
    if (confirm(`Remove ${link.source_url} from active watchlist?`)) {
      router.delete(
        `/workspaces/${workspace.id}/projects/${project.id}/backlinks/watchlist/${link.id}`
      );
    }
  };

  const handleAddSubmit = (e: FormEvent) => {
    e.preventDefault();
    addForm.post(`/workspaces/${workspace.id}/projects/${project.id}/backlinks/watchlist`, {
      onSuccess: () => {
        setAddModalOpen(false);
        addForm.reset();
      },
    });
  };

  const handleCsvSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (!csvForm.data.csv_file) {
      alert('Please select a CSV file to upload.');
      return;
    }

    csvForm.post(`/workspaces/${workspace.id}/projects/${project.id}/backlinks/watchlist/import-csv`, {
      onSuccess: () => {
        setCsvModalOpen(false);
        csvForm.reset();
      },
    });
  };

  const filteredLinks = useMemo(() => {
    const q = searchQuery.toLowerCase().trim();
    return links.filter((item) => {
      if (activeFilter !== 'all' && item.state !== activeFilter) {
        return false;
      }
      if (q !== '') {
        const matchSource = item.source_url.toLowerCase().includes(q);
        const matchTarget = item.target_url.toLowerCase().includes(q);
        const matchAnchor = (item.expected_anchor || '').toLowerCase().includes(q);
        if (!matchSource && !matchTarget && !matchAnchor) {
          return false;
        }
      }
      return true;
    });
  }, [links, activeFilter, searchQuery]);

  return (
    <AppLayout currentWorkspace={workspace}>
      <Head title={`${project.name} - Active Backlink Watchlist & Link-Rot Monitor`} />

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
          <span className="text-white font-medium">Active Watchlist</span>
        </div>

        {/* Page Header */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-slate-800 pb-6">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
                <ShieldAlert className="h-6 w-6 text-purple-400" />
                <span>Active Backlink Watchlist & Link-Rot Monitor</span>
              </h1>
              <span className="rounded-full bg-emerald-500/10 border border-emerald-500/20 px-2.5 py-0.5 text-xs font-mono text-emerald-400">
                $0 Verification Cost
              </span>
            </div>
            <p className="mt-1 text-xs text-slate-400">
              Host-polite single-page crawler inspecting HTTP status, rel attribute flips (dofollow to nofollow/sponsored), link removals, and page-level noindex tags for{' '}
              <span className="text-slate-200 font-mono font-medium">{project.domain}</span>.
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
              href={`/workspaces/${workspace.id}/projects/${project.id}/backlinks/intersect`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-sky-500/10 hover:bg-sky-500/20 border border-sky-500/30 text-sky-300 text-xs font-medium transition cursor-pointer"
            >
              <Sparkles className="h-3.5 w-3.5 text-sky-400" />
              <span>Link Intersect</span>
            </Link>

            <button
              type="button"
              onClick={handleImportDfs}
              disabled={importingDfs}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer disabled:opacity-50"
              title="Import top backlinks into active monitoring"
            >
              <Sparkles className={`h-3.5 w-3.5 text-sky-400 ${importingDfs ? 'animate-spin' : ''}`} />
              <span>{importingDfs ? 'Importing...' : 'Import top backlinks'}</span>
            </button>

            <button
              type="button"
              onClick={() => setCsvModalOpen(true)}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer"
            >
              <Upload className="h-3.5 w-3.5 text-slate-400" />
              <span>Import CSV</span>
            </button>

            <button
              type="button"
              onClick={() => setAddModalOpen(true)}
              className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-purple-600 hover:bg-purple-500 text-white text-xs font-medium transition cursor-pointer shadow-lg shadow-purple-600/20"
            >
              <Plus className="h-3.5 w-3.5" />
              <span>Add Backlink</span>
            </button>

            <button
              type="button"
              onClick={handleVerifyAll}
              disabled={verifyingAll || links.length === 0}
              className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-medium transition cursor-pointer shadow-lg shadow-emerald-600/20 disabled:opacity-50"
            >
              <RefreshCw className={`h-3.5 w-3.5 ${verifyingAll ? 'animate-spin' : ''}`} />
              <span>{verifyingAll ? 'Verifying Watchlist...' : 'Verify All Now'}</span>
            </button>
          </div>
        </div>

        {/* Anomaly Alert Banner */}
        {(metrics.lost > 0 || metrics.rel_changed > 0 || metrics.noindex > 0) && (
          <div className="rounded-xl border border-rose-500/40 bg-rose-950/20 p-4">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <div className="flex items-start gap-3">
                <div className="rounded-lg bg-rose-500/20 p-2 text-rose-400 shrink-0">
                  <AlertTriangle className="h-5 w-5" />
                </div>
                <div>
                  <h3 className="text-sm font-semibold text-rose-300 flex items-center gap-2">
                    <span>Active Link Rot Detected</span>
                    <span className="text-[10px] uppercase font-mono px-1.5 py-0.5 rounded bg-rose-500/20 text-rose-300 border border-rose-500/30 font-bold">
                      {metrics.lost + metrics.rel_changed + metrics.noindex} Issues
                    </span>
                  </h3>
                  <p className="mt-1 text-xs text-rose-300/80">
                    {metrics.lost > 0 ? `${metrics.lost} link(s) removed or unreachable (404/5xx). ` : ''}
                    {metrics.rel_changed > 0 ? `${metrics.rel_changed} link(s) flipped to nofollow or sponsored. ` : ''}
                    {metrics.noindex > 0 ? `${metrics.noindex} source page(s) flagged with noindex tags.` : ''}
                  </p>
                </div>
              </div>

              <div className="flex items-center gap-2 shrink-0">
                <button
                  type="button"
                  onClick={() => setActiveFilter('lost')}
                  className="px-3 py-1.5 rounded-lg bg-rose-500 hover:bg-rose-400 text-slate-950 text-xs font-semibold transition cursor-pointer"
                >
                  View Lost Links ({metrics.lost})
                </button>
                <button
                  type="button"
                  onClick={() => setActiveFilter('rel_changed')}
                  className="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 text-xs font-medium transition cursor-pointer"
                >
                  View Rel Changed ({metrics.rel_changed})
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Metrics Grid */}
        <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
              <Link2 className="h-3.5 w-3.5 text-purple-400" />
              <span>Watchlist Links</span>
            </span>
            <div className="mt-2 text-2xl font-bold font-mono text-white">
              {metrics.total}
            </div>
            <div className="mt-1 text-[11px] text-slate-400">
              Curated monitored links
            </div>
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
              <CheckCircle2 className="h-3.5 w-3.5 text-emerald-400" />
              <span>Active & Verified</span>
            </span>
            <div className="mt-2 text-2xl font-bold font-mono text-emerald-400">
              {metrics.active}
            </div>
            <div className="mt-1 text-[11px] text-slate-400">
              Target present & matching rel
            </div>
          </div>

          <div className={`rounded-xl border p-4 ${
            metrics.lost > 0 ? 'border-rose-500/40 bg-rose-950/20' : 'border-slate-800 bg-slate-900/40'
          }`}>
            <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
              <ArrowDown className={`h-3.5 w-3.5 ${metrics.lost > 0 ? 'text-rose-400' : 'text-slate-400'}`} />
              <span>Lost / Removed</span>
            </span>
            <div className={`mt-2 text-2xl font-bold font-mono ${metrics.lost > 0 ? 'text-rose-400' : 'text-slate-300'}`}>
              {metrics.lost}
            </div>
            <div className="mt-1 text-[11px] text-slate-400">
              Missing link or broken page
            </div>
          </div>

          <div className={`rounded-xl border p-4 ${
            metrics.rel_changed > 0 ? 'border-amber-500/40 bg-amber-950/20' : 'border-slate-800 bg-slate-900/40'
          }`}>
            <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
              <Shield className={`h-3.5 w-3.5 ${metrics.rel_changed > 0 ? 'text-amber-400' : 'text-slate-400'}`} />
              <span>Rel Flipped</span>
            </span>
            <div className={`mt-2 text-2xl font-bold font-mono ${metrics.rel_changed > 0 ? 'text-amber-400' : 'text-slate-300'}`}>
              {metrics.rel_changed}
            </div>
            <div className="mt-1 text-[11px] text-slate-400">
              Nofollow / sponsored changes
            </div>
          </div>

          <div className={`rounded-xl border p-4 ${
            metrics.noindex > 0 ? 'border-amber-500/40 bg-amber-950/20' : 'border-slate-800 bg-slate-900/40'
          }`}>
            <span className="text-slate-400 text-xs font-medium flex items-center gap-1.5">
              <AlertTriangle className={`h-3.5 w-3.5 ${metrics.noindex > 0 ? 'text-amber-400' : 'text-slate-400'}`} />
              <span>Page Noindex</span>
            </span>
            <div className={`mt-2 text-2xl font-bold font-mono ${metrics.noindex > 0 ? 'text-amber-400' : 'text-slate-300'}`}>
              {metrics.noindex}
            </div>
            <div className="mt-1 text-[11px] text-slate-400">
              Source robots tag blocks index
            </div>
          </div>
        </div>

        {/* Toolbar */}
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-slate-900/40 border border-slate-800 p-3 rounded-xl">
          <div className="relative flex-1 max-w-sm">
            <input
              type="text"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Search source URL, target URL, or anchor..."
              className="w-full rounded-lg bg-slate-950 border border-slate-800 pl-8 pr-3 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-purple-500 transition"
            />
            <Search className="h-3.5 w-3.5 text-slate-500 absolute left-2.5 top-2 pointer-events-none" />
          </div>

          <div className="flex items-center gap-2">
            <span className="text-xs text-slate-400 flex items-center gap-1">
              <Filter className="h-3 w-3 text-slate-500" />
              <span>Filter:</span>
            </span>
            <div className="inline-flex rounded-lg bg-slate-950 border border-slate-800 p-0.5 text-xs">
              <button
                type="button"
                onClick={() => setActiveFilter('all')}
                className={`px-2.5 py-1 rounded-md font-medium transition cursor-pointer ${
                  activeFilter === 'all'
                    ? 'bg-slate-800 text-white'
                    : 'text-slate-400 hover:text-white'
                }`}
              >
                All ({metrics.total})
              </button>
              <button
                type="button"
                onClick={() => setActiveFilter('active')}
                className={`px-2.5 py-1 rounded-md font-medium transition cursor-pointer ${
                  activeFilter === 'active'
                    ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/30'
                    : 'text-emerald-400/80 hover:text-emerald-300'
                }`}
              >
                Active ({metrics.active})
              </button>
              <button
                type="button"
                onClick={() => setActiveFilter('lost')}
                className={`px-2.5 py-1 rounded-md font-medium transition cursor-pointer ${
                  activeFilter === 'lost'
                    ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30'
                    : 'text-rose-400/80 hover:text-rose-300'
                }`}
              >
                Lost ({metrics.lost})
              </button>
              <button
                type="button"
                onClick={() => setActiveFilter('rel_changed')}
                className={`px-2.5 py-1 rounded-md font-medium transition cursor-pointer ${
                  activeFilter === 'rel_changed'
                    ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30'
                    : 'text-amber-400/80 hover:text-amber-300'
                }`}
              >
                Rel Changed ({metrics.rel_changed})
              </button>
              <button
                type="button"
                onClick={() => setActiveFilter('error')}
                className={`px-2.5 py-1 rounded-md font-medium transition cursor-pointer ${
                  activeFilter === 'error'
                    ? 'bg-slate-700 text-slate-200'
                    : 'text-slate-400 hover:text-slate-300'
                }`}
              >
                Errors ({metrics.error})
              </button>
            </div>
          </div>
        </div>

        {/* Watchlist Table */}
        <div className="rounded-xl border border-slate-800 bg-slate-900/40 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-950/60 border-b border-slate-800 text-slate-400">
                <tr>
                  <th className="py-3 px-4 font-medium">Source URL (Page Hosting Link)</th>
                  <th className="py-3 px-3 font-medium">Target URL</th>
                  <th className="py-3 px-3 font-medium">Anchor Text</th>
                  <th className="py-3 px-3 text-center font-medium">Expected vs Observed Rel</th>
                  <th className="py-3 px-3 text-center font-medium">HTTP</th>
                  <th className="py-3 px-3 text-center font-medium">State</th>
                  <th className="py-3 px-3 text-right font-medium">Last Verified</th>
                  <th className="py-3 px-4 text-right font-medium">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/60">
                {filteredLinks.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="py-12 text-center text-slate-500">
                      <div className="max-w-sm mx-auto space-y-2">
                        <Link2 className="h-8 w-8 text-slate-600 mx-auto" />
                        <p className="text-sm font-medium text-slate-300">No monitored backlinks found</p>
                        <p className="text-xs text-slate-500">
                          Add critical customer or partner backlinks to monitor for link removals, rel=&quot;nofollow&quot; flips, and 404s.
                        </p>
                        <div className="pt-2 flex items-center justify-center gap-2">
                          <button
                            type="button"
                            onClick={() => setAddModalOpen(true)}
                            className="px-3 py-1.5 rounded-lg bg-purple-600 hover:bg-purple-500 text-white text-xs font-medium transition cursor-pointer"
                          >
                            Add First Backlink
                          </button>
                          <button
                            type="button"
                            onClick={handleImportDfs}
                            disabled={importingDfs}
                            className="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 text-xs font-medium transition cursor-pointer"
                          >
                            Import top backlinks
                          </button>
                        </div>
                      </div>
                    </td>
                  </tr>
                ) : (
                  filteredLinks.map((item) => (
                    <tr key={item.id} className="hover:bg-slate-800/30 transition text-slate-300">
                      {/* Source URL */}
                      <td className="py-3 px-4 max-w-xs truncate">
                        <div className="flex items-center gap-1.5">
                          <Globe className="h-3.5 w-3.5 text-purple-400 shrink-0" />
                          <span className="font-mono text-xs text-white truncate" title={item.source_url}>
                            {item.source_url}
                          </span>
                          <a
                            href={item.source_url}
                            target="_blank"
                            rel="noreferrer"
                            className="text-slate-500 hover:text-slate-300 transition shrink-0"
                          >
                            <ExternalLink className="h-3 w-3" />
                          </a>
                          <button
                            type="button"
                            onClick={() => handleCopy(item.source_url)}
                            className="text-slate-500 hover:text-slate-300 transition shrink-0"
                            title="Copy source URL"
                          >
                            {copiedUrl === item.source_url ? (
                              <CheckCircle2 className="h-3 w-3 text-emerald-400" />
                            ) : (
                              <Copy className="h-3 w-3" />
                            )}
                          </button>
                        </div>
                      </td>

                      {/* Target URL */}
                      <td className="py-3 px-3 max-w-[200px] truncate">
                        <span className="font-mono text-[11px] text-slate-400 truncate block" title={item.target_url}>
                          {item.target_url.replace(project.canonicalUrl, '/')}
                        </span>
                      </td>

                      {/* Expected Anchor */}
                      <td className="py-3 px-3 max-w-[150px] truncate">
                        <span className="text-slate-300 truncate block" title={item.expected_anchor || '—'}>
                          {item.expected_anchor || <span className="text-slate-600">—</span>}
                        </span>
                      </td>

                      {/* Rel Match */}
                      <td className="py-3 px-3 text-center">
                        <div className="inline-flex items-center gap-1">
                          <span className="text-[10px] font-mono uppercase px-1.5 py-0.5 rounded bg-slate-800 text-slate-400 border border-slate-700">
                            {item.expected_rel || 'dofollow'}
                          </span>
                          {item.last_observed_rel && (
                            <>
                              <span className="text-slate-600">&rarr;</span>
                              <span className={`text-[10px] font-mono uppercase px-1.5 py-0.5 rounded border ${
                                item.last_observed_rel === item.expected_rel
                                  ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20'
                                  : 'bg-amber-500/20 text-amber-300 border-amber-500/40 font-bold'
                              }`}>
                                {item.last_observed_rel}
                              </span>
                            </>
                          )}
                        </div>
                      </td>

                      {/* HTTP Status & Noindex */}
                      <td className="py-3 px-3 text-center">
                        <div className="inline-flex flex-col items-center gap-0.5">
                          <span className={`font-mono text-[11px] font-semibold ${
                            item.last_http_status === 200
                              ? 'text-emerald-400'
                              : item.last_http_status && item.last_http_status >= 400
                              ? 'text-rose-400'
                              : 'text-slate-500'
                          }`}>
                            {item.last_http_status ? `${item.last_http_status}` : '—'}
                          </span>
                          {item.last_page_noindex && (
                            <span className="text-[9px] font-mono uppercase px-1 rounded bg-rose-500/20 text-rose-300 border border-rose-500/30">
                              NOINDEX
                            </span>
                          )}
                        </div>
                      </td>

                      {/* State Badge */}
                      <td className="py-3 px-3 text-center">
                        <span className={`inline-flex items-center px-2 py-0.5 rounded text-[10px] font-mono font-bold uppercase border ${
                          item.state === 'active'
                            ? 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30'
                            : item.state === 'lost'
                            ? 'bg-rose-500/20 text-rose-300 border-rose-500/30'
                            : item.state === 'rel_changed'
                            ? 'bg-amber-500/20 text-amber-300 border-amber-500/30'
                            : 'bg-slate-800 text-slate-400 border-slate-700'
                        }`}>
                          {item.state}
                        </span>
                      </td>

                      {/* Last Verified */}
                      <td className="py-3 px-3 text-right font-mono text-[11px] text-slate-400">
                        {formatRelativeTime(item.last_verified_at)}
                      </td>

                      {/* Actions */}
                      <td className="py-3 px-4 text-right">
                        <div className="flex items-center justify-end gap-1.5">
                          <button
                            type="button"
                            onClick={() => handleVerifySingle(item.id)}
                            disabled={verifyingId === item.id}
                            className="p-1 rounded text-slate-400 hover:text-white hover:bg-slate-800 transition cursor-pointer disabled:opacity-50"
                            title="Verify backlink state now"
                          >
                            <RefreshCw className={`h-3.5 w-3.5 ${verifyingId === item.id ? 'animate-spin text-purple-400' : ''}`} />
                          </button>
                          <button
                            type="button"
                            onClick={() => handleDelete(item)}
                            className="p-1 rounded text-slate-400 hover:text-rose-400 hover:bg-rose-500/10 transition cursor-pointer"
                            title="Delete monitored backlink"
                          >
                            <Trash2 className="h-3.5 w-3.5" />
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

        {/* Modal: Add Single Backlink */}
        {addModalOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
            <div className="relative w-full max-w-lg rounded-xl border border-slate-800 bg-slate-900 p-6 shadow-2xl">
              <button
                type="button"
                onClick={() => setAddModalOpen(false)}
                className="absolute top-4 right-4 text-slate-400 hover:text-white transition"
              >
                <X className="h-4 w-4" />
              </button>

              <h3 className="text-base font-semibold text-white flex items-center gap-2">
                <Plus className="h-4 w-4 text-purple-400" />
                <span>Add Monitored Backlink</span>
              </h3>
              <p className="mt-1 text-xs text-slate-400">
                Track a high-value external link pointing to {project.domain}.
              </p>

              <form onSubmit={handleAddSubmit} className="mt-4 space-y-4">
                <div>
                  <label className="block text-xs font-medium text-slate-300">
                    Source URL (Where the backlink is hosted) *
                  </label>
                  <input
                    type="url"
                    required
                    value={addForm.data.source_url}
                    onChange={(e) => addForm.setData('source_url', e.target.value)}
                    placeholder="https://techcrunch.com/article/top-tools"
                    className="mt-1 w-full rounded-lg bg-slate-950 border border-slate-800 px-3 py-2 text-xs text-white placeholder-slate-600 focus:outline-none focus:border-purple-500"
                  />
                  {addForm.errors.source_url && (
                    <span className="text-[11px] text-rose-400 mt-0.5 block">{addForm.errors.source_url}</span>
                  )}
                </div>

                <div>
                  <label className="block text-xs font-medium text-slate-300">
                    Target URL (Page on your site receiving the link)
                  </label>
                  <input
                    type="url"
                    value={addForm.data.target_url}
                    onChange={(e) => addForm.setData('target_url', e.target.value)}
                    placeholder={project.canonicalUrl}
                    className="mt-1 w-full rounded-lg bg-slate-950 border border-slate-800 px-3 py-2 text-xs text-white placeholder-slate-600 focus:outline-none focus:border-purple-500"
                  />
                  {addForm.errors.target_url && (
                    <span className="text-[11px] text-rose-400 mt-0.5 block">{addForm.errors.target_url}</span>
                  )}
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-xs font-medium text-slate-300">
                      Expected Anchor Text
                    </label>
                    <input
                      type="text"
                      value={addForm.data.expected_anchor}
                      onChange={(e) => addForm.setData('expected_anchor', e.target.value)}
                      placeholder="e.g. Coqui SEO platform"
                      className="mt-1 w-full rounded-lg bg-slate-950 border border-slate-800 px-3 py-2 text-xs text-white placeholder-slate-600 focus:outline-none focus:border-purple-500"
                    />
                  </div>

                  <div>
                    <label className="block text-xs font-medium text-slate-300">
                      Expected Rel Attribute
                    </label>
                    <select
                      value={addForm.data.expected_rel}
                      onChange={(e) => addForm.setData('expected_rel', e.target.value)}
                      className="mt-1 w-full rounded-lg bg-slate-950 border border-slate-800 px-3 py-2 text-xs text-white focus:outline-none focus:border-purple-500"
                    >
                      <option value="dofollow">Dofollow</option>
                      <option value="nofollow">Nofollow</option>
                      <option value="sponsored">Sponsored</option>
                      <option value="ugc">UGC</option>
                    </select>
                  </div>
                </div>

                <div className="pt-2 flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setAddModalOpen(false)}
                    className="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition cursor-pointer"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={addForm.processing}
                    className="px-4 py-1.5 rounded-lg bg-purple-600 hover:bg-purple-500 text-white text-xs font-semibold transition cursor-pointer disabled:opacity-50"
                  >
                    {addForm.processing ? 'Saving...' : 'Add and Verify'}
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* Modal: Import CSV */}
        {csvModalOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
            <div className="relative w-full max-w-lg rounded-xl border border-slate-800 bg-slate-900 p-6 shadow-2xl">
              <button
                type="button"
                onClick={() => setCsvModalOpen(false)}
                className="absolute top-4 right-4 text-slate-400 hover:text-white transition"
              >
                <X className="h-4 w-4" />
              </button>

              <h3 className="text-base font-semibold text-white flex items-center gap-2">
                <FileSpreadsheet className="h-4 w-4 text-emerald-400" />
                <span>Import Watchlist Backlinks from CSV</span>
              </h3>
              <p className="mt-1 text-xs text-slate-400">
                Bulk upload 100 to 500 high-value backlinks for active monitoring.
              </p>

              <div className="mt-3 rounded-lg border border-slate-800 bg-slate-950 p-3 text-[11px] font-mono text-slate-400">
                <div className="text-slate-300 font-semibold mb-1">CSV Header Format:</div>
                <code>source_url,target_url,expected_anchor,expected_rel</code>
                <div className="mt-1 text-slate-500 text-[10px]">
                  Example: https://forbes.com/article,https://example.com/demo,Best Platform,dofollow
                </div>
              </div>

              <form onSubmit={handleCsvSubmit} className="mt-4 space-y-4">
                <div>
                  <label className="block text-xs font-medium text-slate-300">
                    Select CSV File *
                  </label>
                  <input
                    type="file"
                    accept=".csv,text/csv,text/plain"
                    required
                    onChange={(e) => csvForm.setData('csv_file', e.target.files ? e.target.files[0] : null)}
                    className="mt-1 w-full rounded-lg bg-slate-950 border border-slate-800 px-3 py-2 text-xs text-slate-400 file:mr-3 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:font-semibold file:bg-purple-600 file:text-white hover:file:bg-purple-500 cursor-pointer"
                  />
                  {csvForm.errors.csv_file && (
                    <span className="text-[11px] text-rose-400 mt-0.5 block">{csvForm.errors.csv_file}</span>
                  )}
                </div>

                <div className="pt-2 flex items-center justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setCsvModalOpen(false)}
                    className="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition cursor-pointer"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={csvForm.processing}
                    className="px-4 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold transition cursor-pointer disabled:opacity-50"
                  >
                    {csvForm.processing ? 'Uploading...' : 'Upload & Import'}
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
