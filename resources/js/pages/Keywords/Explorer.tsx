import { Head, Link } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowDown,
  ArrowLeft,
  ArrowUp,
  BarChart3,
  Check,
  CheckCircle2,
  ChevronRight,
  Compass,
  Copy,
  Download,
  ExternalLink,
  Filter,
  Globe,
  HelpCircle,
  Laptop,
  Layers,
  Lightbulb,
  ListFilter,
  Loader2,
  Plus,
  RefreshCw,
  Search,
  ShoppingCart,
  Smartphone,
  Sparkles,
  TrendingUp,
  X,
  Zap,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface ProjectDetails {
  id: number;
  name: string;
  domain: string;
  canonical_url: string;
  location_code: number;
  language_code: string;
}

interface MonthlySearch {
  year: number;
  month: number;
  search_volume: number;
}

interface KeywordItem {
  keyword: string;
  location_code: number;
  language_code: string;
  search_volume: number | null;
  cpc: number | null;
  competition: number | null;
  competition_level: string | null;
  keyword_difficulty: number | null;
  main_intent: string | null;
  secondary_intents: string[];
  monthly_searches: MonthlySearch[];
  cached: boolean;
  updated_at?: string;
}

interface ExplorerSummary {
  total_keywords: number;
  total_volume: number;
  avg_difficulty: number | null;
  cached_count: number;
  fetched_count: number;
  intent_breakdown: {
    informational: number;
    commercial: number;
    transactional: number;
    navigational: number;
  };
}

interface KeywordExplorerProps {
  workspace: WorkspaceSummary;
  project: ProjectDetails;
  trackedKeywords: string[];
  defaultLocationCode: number;
  defaultLanguageCode: string;
}

const COMMON_LOCATIONS = [
  { code: 2840, label: 'United States (US)' },
  { code: 2826, label: 'United Kingdom (UK)' },
  { code: 2124, label: 'Canada (CA)' },
  { code: 2036, label: 'Australia (AU)' },
  { code: 2276, label: 'Germany (DE)' },
  { code: 2250, label: 'France (FR)' },
  { code: 2724, label: 'Spain (ES)' },
  { code: 2380, label: 'Italy (IT)' },
  { code: 2356, label: 'India (IN)' },
  { code: 2076, label: 'Brazil (BR)' },
];

const COMMON_LANGUAGES = [
  { code: 'en', label: 'English (en)' },
  { code: 'es', label: 'Spanish (es)' },
  { code: 'de', label: 'German (de)' },
  { code: 'fr', label: 'French (fr)' },
  { code: 'it', label: 'Italian (it)' },
  { code: 'pt', label: 'Portuguese (pt)' },
];

function getCsrfHeaders(): Record<string, string> {
  if (typeof document === 'undefined') return {};
  const meta = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null;
  if (meta?.content) {
    return { 'X-CSRF-TOKEN': meta.content };
  }
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  if (match) {
    return { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) };
  }
  return {};
}

function formatNumber(num: number | null | undefined): string {
  if (num === null || num === undefined) return '-';
  if (num >= 1_000_000) return `${(num / 1_000_000).toFixed(1)}M`;
  if (num >= 1_000) return `${(num / 1_000).toFixed(1)}K`;
  return num.toLocaleString();
}

function MiniTrendBars({ monthlySearches }: { monthlySearches: MonthlySearch[] }) {
  if (!monthlySearches || monthlySearches.length === 0) {
    return <span className="text-[11px] text-slate-600">-</span>;
  }

  // Display the latest 12 months in chronological order
  const sorted = [...monthlySearches]
    .sort((a, b) => a.year * 100 + a.month - (b.year * 100 + b.month))
    .slice(-12);

  const maxVal = Math.max(...sorted.map((m) => m.search_volume), 1);

  return (
    <div className="flex items-end gap-[2px] h-4 w-14" title="12-month search volume trend">
      {sorted.map((m, idx) => {
        const heightPct = Math.max(15, Math.round((m.search_volume / maxVal) * 100));
        return (
          <div
            key={idx}
            className="w-[3px] rounded-t-xs bg-sky-500/70 hover:bg-sky-400 transition"
            style={{ height: `${heightPct}%` }}
            title={`${m.year}-${String(m.month).padStart(2, '0')}: ${m.search_volume.toLocaleString()}`}
          />
        );
      })}
    </div>
  );
}

function DifficultyPill({ difficulty }: { difficulty: number | null | undefined }) {
  if (difficulty === null || difficulty === undefined) {
    return <span className="text-slate-500 text-xs">-</span>;
  }

  let colorClasses = 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30';
  let label = 'Easy';

  if (difficulty >= 70) {
    colorClasses = 'bg-rose-500/10 text-rose-400 border-rose-500/30';
    label = 'Hard';
  } else if (difficulty >= 30) {
    colorClasses = 'bg-amber-500/10 text-amber-400 border-amber-500/30';
    label = 'Medium';
  }

  return (
    <div className="flex items-center gap-1.5">
      <span
        className={`inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] font-mono font-medium border ${colorClasses}`}
      >
        <span>{difficulty}</span>
      </span>
      <span className="text-[10px] text-slate-500 font-medium">{label}</span>
    </div>
  );
}

function IntentPill({ intent }: { intent: string | null | undefined }) {
  if (!intent) {
    return <span className="text-slate-500 text-xs">-</span>;
  }

  const norm = intent.toLowerCase();

  switch (norm) {
    case 'informational':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-sky-500/10 text-sky-400 border border-sky-500/20">
          <Lightbulb className="h-2.5 w-2.5" /> Informational
        </span>
      );
    case 'commercial':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-purple-500/10 text-purple-400 border border-purple-500/20">
          <ShoppingCart className="h-2.5 w-2.5" /> Commercial
        </span>
      );
    case 'transactional':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
          <Zap className="h-2.5 w-2.5" /> Transactional
        </span>
      );
    case 'navigational':
      return (
        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-amber-500/10 text-amber-400 border border-amber-500/20">
          <Compass className="h-2.5 w-2.5" /> Navigational
        </span>
      );
    default:
      return (
        <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-800 text-slate-400 border border-slate-700 capitalize">
          {norm}
        </span>
      );
  }
}

export default function KeywordExplorer({
  workspace,
  project,
  trackedKeywords,
  defaultLocationCode,
  defaultLanguageCode,
}: KeywordExplorerProps) {
  const [activeTab, setActiveTab] = useState<'related' | 'overview'>('related');

  // Query forms
  const [seedKeyword, setSeedKeyword] = useState('');
  const [bulkInput, setBulkInput] = useState('');
  const [locationCode, setLocationCode] = useState<number>(defaultLocationCode || 2840);
  const [languageCode, setLanguageCode] = useState<string>(defaultLanguageCode || 'en');
  const [limit, setLimit] = useState<number>(100);

  // Results state
  const [results, setResults] = useState<KeywordItem[]>([]);
  const [summary, setSummary] = useState<ExplorerSummary | null>(null);
  const [loading, setLoading] = useState<boolean>(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  // Local set of tracked keywords
  const [trackedSet, setTrackedSet] = useState<Set<string>>(
    () => new Set(trackedKeywords.map((k) => k.toLowerCase()))
  );

  // Table selections
  const [selectedKeywords, setSelectedKeywords] = useState<Set<string>>(new Set());

  // Table filters & sorting
  const [searchFilter, setSearchFilter] = useState('');
  const [intentFilter, setIntentFilter] = useState<string>('all');
  const [kdFilter, setKdFilter] = useState<string>('all');
  const [volumeFilter, setVolumeFilter] = useState<string>('all');
  const [sortBy, setSortBy] = useState<'volume' | 'kd_asc' | 'kd_desc' | 'cpc' | 'alpha'>('volume');

  // Track modal
  const [trackModalOpen, setTrackModalOpen] = useState(false);
  const [keywordsToTrack, setKeywordsToTrack] = useState<KeywordItem[]>([]);
  const [trackDevice, setTrackDevice] = useState<'desktop' | 'mobile'>('desktop');
  const [trackCadence, setTrackCadence] = useState<'daily' | 'weekly'>('weekly');
  const [trackingLoading, setTrackingLoading] = useState(false);

  // Copy indicator
  const [copiedKeyword, setCopiedKeyword] = useState<string | null>(null);

  const handleCopy = (kw: string) => {
    navigator.clipboard.writeText(kw);
    setCopiedKeyword(kw);
    setTimeout(() => setCopiedKeyword(null), 2000);
  };

  const handleSearchRelated = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!seedKeyword.trim()) return;

    setLoading(true);
    setErrorMsg(null);
    setSuccessMsg(null);
    setSelectedKeywords(new Set());

    try {
      const response = await fetch(
        `/workspaces/${workspace.id}/projects/${project.id}/keyword-explorer/related`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...getCsrfHeaders(),
          },
          body: JSON.stringify({
            keyword: seedKeyword.trim(),
            location_code: locationCode,
            language_code: languageCode,
            limit: limit,
          }),
        }
      );

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Failed to search related keywords.');
      }

      setResults(data.keywords || []);
      setSummary(data.summary || null);
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'An unexpected error occurred.';
      setErrorMsg(message);
    } finally {
      setLoading(false);
    }
  };

  const handleAnalyzeBulk = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!bulkInput.trim()) return;

    setLoading(true);
    setErrorMsg(null);
    setSuccessMsg(null);
    setSelectedKeywords(new Set());

    try {
      const response = await fetch(
        `/workspaces/${workspace.id}/projects/${project.id}/keyword-explorer/overview`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...getCsrfHeaders(),
          },
          body: JSON.stringify({
            keywords: bulkInput,
            location_code: locationCode,
            language_code: languageCode,
          }),
        }
      );

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Failed to analyze keywords.');
      }

      setResults(data.keywords || []);
      setSummary(data.summary || null);
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'An unexpected error occurred.';
      setErrorMsg(message);
    } finally {
      setLoading(false);
    }
  };

  const openTrackSingle = (item: KeywordItem) => {
    setKeywordsToTrack([item]);
    setTrackModalOpen(true);
  };

  const openTrackSelected = () => {
    const items = results.filter((r) => selectedKeywords.has(r.keyword.toLowerCase()));
    if (items.length === 0) return;
    setKeywordsToTrack(items);
    setTrackModalOpen(true);
  };

  const submitTrackKeywords = async () => {
    if (keywordsToTrack.length === 0) return;

    setTrackingLoading(true);
    setErrorMsg(null);

    try {
      const CHUNK_SIZE = 100;
      let totalAdded = 0;

      for (let i = 0; i < keywordsToTrack.length; i += CHUNK_SIZE) {
        const chunk = keywordsToTrack.slice(i, i + CHUNK_SIZE);
        const response = await fetch(
          `/workspaces/${workspace.id}/projects/${project.id}/keyword-explorer/track`,
          {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
              ...getCsrfHeaders(),
            },
            body: JSON.stringify({
              keywords: chunk.map((k) => ({
                keyword: k.keyword,
                search_volume: k.search_volume,
                keyword_difficulty: k.keyword_difficulty,
                location_code: k.location_code,
                language_code: k.language_code,
              })),
              device: trackDevice,
              cadence: trackCadence,
            }),
          }
        );

        const data = await response.json();

        if (!response.ok) {
          throw new Error(data.message || 'Failed to add keywords to Rank Tracker.');
        }

        totalAdded += typeof data.count === 'number' ? data.count : chunk.length;
      }

      // Update local set
      setTrackedSet((prev) => {
        const next = new Set(prev);
        keywordsToTrack.forEach((k) => next.add(k.keyword.toLowerCase()));
        return next;
      });

      setSelectedKeywords(new Set());
      setTrackModalOpen(false);
      setSuccessMsg(
        `Added ${totalAdded} keyword(s) to Rank Tracker for ${trackDevice} monitoring!`
      );
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to track keywords.';
      setErrorMsg(message);
    } finally {
      setTrackingLoading(false);
    }
  };

  // Filtered & sorted keywords
  const filteredKeywords = useMemo(() => {
    let list = results;

    if (searchFilter.trim()) {
      const q = searchFilter.toLowerCase().trim();
      list = list.filter((item) => item.keyword.toLowerCase().includes(q));
    }

    if (intentFilter !== 'all') {
      list = list.filter((item) => item.main_intent?.toLowerCase() === intentFilter);
    }

    if (kdFilter === 'easy') {
      list = list.filter((item) => item.keyword_difficulty !== null && item.keyword_difficulty < 30);
    } else if (kdFilter === 'medium') {
      list = list.filter(
        (item) =>
          item.keyword_difficulty !== null &&
          item.keyword_difficulty >= 30 &&
          item.keyword_difficulty < 70
      );
    } else if (kdFilter === 'hard') {
      list = list.filter(
        (item) => item.keyword_difficulty !== null && item.keyword_difficulty >= 70
      );
    }

    if (volumeFilter === '100') {
      list = list.filter((item) => (item.search_volume ?? 0) >= 100);
    } else if (volumeFilter === '1000') {
      list = list.filter((item) => (item.search_volume ?? 0) >= 1_000);
    } else if (volumeFilter === '10000') {
      list = list.filter((item) => (item.search_volume ?? 0) >= 10_000);
    }

    return [...list].sort((a, b) => {
      if (sortBy === 'volume') {
        return (b.search_volume ?? -1) - (a.search_volume ?? -1);
      }
      if (sortBy === 'kd_asc') {
        return (a.keyword_difficulty ?? 999) - (b.keyword_difficulty ?? 999);
      }
      if (sortBy === 'kd_desc') {
        return (b.keyword_difficulty ?? -1) - (a.keyword_difficulty ?? -1);
      }
      if (sortBy === 'cpc') {
        return (b.cpc ?? -1) - (a.cpc ?? -1);
      }
      if (sortBy === 'alpha') {
        return a.keyword.localeCompare(b.keyword);
      }
      return 0;
    });
  }, [results, searchFilter, intentFilter, kdFilter, volumeFilter, sortBy]);

  const toggleSelectAll = () => {
    if (selectedKeywords.size === filteredKeywords.length && filteredKeywords.length > 0) {
      setSelectedKeywords(new Set());
    } else {
      setSelectedKeywords(new Set(filteredKeywords.map((k) => k.keyword.toLowerCase())));
    }
  };

  const toggleSelectOne = (kw: string) => {
    const norm = kw.toLowerCase();
    setSelectedKeywords((prev) => {
      const next = new Set(prev);
      if (next.has(norm)) {
        next.delete(norm);
      } else {
        next.add(norm);
      }
      return next;
    });
  };

  const handleExportCsv = () => {
    const itemsToExport =
      selectedKeywords.size > 0
        ? filteredKeywords.filter((k) => selectedKeywords.has(k.keyword.toLowerCase()))
        : filteredKeywords;

    if (itemsToExport.length === 0) return;

    const headers = [
      'Keyword',
      'Search Volume',
      'Keyword Difficulty',
      'Search Intent',
      'CPC (USD)',
      'Competition',
      'Tracked',
    ];

    const rows = itemsToExport.map((item) => [
      `"${item.keyword.replace(/"/g, '""')}"`,
      item.search_volume ?? '',
      item.keyword_difficulty ?? '',
      item.main_intent ?? '',
      item.cpc ? item.cpc.toFixed(2) : '',
      item.competition ?? '',
      trackedSet.has(item.keyword.toLowerCase()) ? 'Yes' : 'No',
    ]);

    const csvContent = [headers.join(','), ...rows.map((r) => r.join(','))].join('\r\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute(
      'download',
      `keyword-research-${project.domain}-${new Date().toISOString().slice(0, 10)}.csv`
    );
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  };

  const bulkLineCount = useMemo(() => {
    if (!bulkInput.trim()) return 0;
    return bulkInput
      .split(/[\r\n,]+/)
      .map((l) => l.trim())
      .filter(Boolean).length;
  }, [bulkInput]);

  return (
    <AppLayout currentWorkspace={workspace}>
      <Head title={`${project.name} - Keyword Explorer & Search Volume`} />

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
          <span className="text-white font-medium">Keyword Explorer</span>
        </div>

        {/* Page Header */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-slate-800 pb-6">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
                <Sparkles className="h-6 w-6 text-sky-400" />
                <span>Keyword Explorer & Search Volume</span>
              </h1>
              <span className="rounded-full bg-sky-500/10 border border-sky-500/20 px-2.5 py-0.5 text-xs font-mono text-sky-400">
                700-Batch Caching
              </span>
            </div>
            <p className="mt-1 text-xs text-slate-400">
              Interactive keyword research powered by DataForSEO Labs with 30-day cross-project caching and instant Rank Tracker integration for{' '}
              <span className="text-slate-200 font-mono font-medium">{project.domain}</span>.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <Link
              href={`/workspaces/${workspace.id}/projects/${project.id}/keywords`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer"
            >
              <Search className="h-3.5 w-3.5 text-emerald-400" />
              <span>Rank Tracker</span>
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

        {/* Alert Messages */}
        {errorMsg && (
          <div className="rounded-xl border border-red-500/30 bg-red-500/10 p-4 text-xs text-red-300 flex items-start gap-3">
            <AlertTriangle className="h-4 w-4 text-red-400 shrink-0 mt-0.5" />
            <div className="flex-1">
              <span className="font-semibold">Error:</span> {errorMsg}
            </div>
            <button
              type="button"
              onClick={() => setErrorMsg(null)}
              className="text-red-400 hover:text-red-200"
            >
              <X className="h-3.5 w-3.5" />
            </button>
          </div>
        )}

        {successMsg && (
          <div className="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-xs text-emerald-300 flex items-start gap-3">
            <CheckCircle2 className="h-4 w-4 text-emerald-400 shrink-0 mt-0.5" />
            <div className="flex-1 font-medium">{successMsg}</div>
            <button
              type="button"
              onClick={() => setSuccessMsg(null)}
              className="text-emerald-400 hover:text-emerald-200"
            >
              <X className="h-3.5 w-3.5" />
            </button>
          </div>
        )}

        {/* Main Search Panel */}
        <div className="rounded-2xl border border-slate-800 bg-slate-900/60 p-5 space-y-4 shadow-xl">
          {/* Mode Switcher Tabs */}
          <div className="flex items-center gap-1 border-b border-slate-800 pb-3">
            <button
              type="button"
              onClick={() => {
                setActiveTab('related');
                setErrorMsg(null);
              }}
              className={`inline-flex items-center gap-2 px-4 py-2 rounded-lg text-xs font-medium transition cursor-pointer ${
                activeTab === 'related'
                  ? 'bg-sky-500/15 text-sky-400 border border-sky-500/30 shadow-sm'
                  : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60'
              }`}
            >
              <Lightbulb className="h-3.5 w-3.5" />
              <span>Related Keywords & Ideas</span>
            </button>

            <button
              type="button"
              onClick={() => {
                setActiveTab('overview');
                setErrorMsg(null);
              }}
              className={`inline-flex items-center gap-2 px-4 py-2 rounded-lg text-xs font-medium transition cursor-pointer ${
                activeTab === 'overview'
                  ? 'bg-sky-500/15 text-sky-400 border border-sky-500/30 shadow-sm'
                  : 'text-slate-400 hover:text-slate-200 hover:bg-slate-800/60'
              }`}
            >
              <Layers className="h-3.5 w-3.5" />
              <span>Batch Search Volume (Up to 700)</span>
            </button>
          </div>

          {/* Related Keywords Form */}
          {activeTab === 'related' && (
            <form onSubmit={handleSearchRelated} className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-12 gap-3">
                <div className="md:col-span-6 relative">
                  <input
                    type="text"
                    value={seedKeyword}
                    onChange={(e) => setSeedKeyword(e.target.value)}
                    placeholder="Enter seed keyword (e.g. 'seo tools', 'react framework')..."
                    disabled={loading}
                    className="w-full rounded-xl bg-slate-950 border border-slate-800 px-4 py-2.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 transition"
                  />
                  <Search className="h-4 w-4 text-slate-500 absolute right-3.5 top-3 pointer-events-none" />
                </div>

                <div className="md:col-span-2">
                  <select
                    value={locationCode}
                    onChange={(e) => setLocationCode(Number(e.target.value))}
                    disabled={loading}
                    className="w-full rounded-xl bg-slate-950 border border-slate-800 px-3 py-2.5 text-xs text-slate-200 focus:outline-none focus:border-sky-500 transition"
                  >
                    {COMMON_LOCATIONS.map((loc) => (
                      <option key={loc.code} value={loc.code}>
                        {loc.label}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="md:col-span-2">
                  <select
                    value={languageCode}
                    onChange={(e) => setLanguageCode(e.target.value)}
                    disabled={loading}
                    className="w-full rounded-xl bg-slate-950 border border-slate-800 px-3 py-2.5 text-xs text-slate-200 focus:outline-none focus:border-sky-500 transition"
                  >
                    {COMMON_LANGUAGES.map((lang) => (
                      <option key={lang.code} value={lang.code}>
                        {lang.label}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="md:col-span-2">
                  <button
                    type="submit"
                    disabled={loading || !seedKeyword.trim()}
                    className="w-full h-full min-h-[38px] inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-xl bg-sky-500 hover:bg-sky-400 disabled:opacity-50 disabled:cursor-not-allowed text-slate-950 font-semibold text-xs transition shadow-lg shadow-sky-500/10 cursor-pointer"
                  >
                    {loading ? (
                      <>
                        <Loader2 className="h-3.5 w-3.5 animate-spin" />
                        <span>Searching...</span>
                      </>
                    ) : (
                      <>
                        <Sparkles className="h-3.5 w-3.5" />
                        <span>Explore Keywords</span>
                      </>
                    )}
                  </button>
                </div>
              </div>

              <div className="flex items-center justify-between text-[11px] text-slate-500">
                <span>Returns top related search queries, search volume, KD, CPC, and intent from Google SERPs.</span>
                <span className="font-mono">Cache TTL: 30 days ($0 re-lookups)</span>
              </div>
            </form>
          )}

          {/* Bulk Keyword Overview Form */}
          {activeTab === 'overview' && (
            <form onSubmit={handleAnalyzeBulk} className="space-y-4">
              <div className="space-y-2">
                <textarea
                  value={bulkInput}
                  onChange={(e) => setBulkInput(e.target.value)}
                  placeholder="Paste or type up to 700 keywords (one per line or comma-separated)&#10;e.g.&#10;seo audit tool&#10;backlink checker&#10;rank tracking software"
                  rows={4}
                  disabled={loading}
                  className="w-full rounded-xl bg-slate-950 border border-slate-800 p-3 text-xs text-white font-mono placeholder-slate-600 focus:outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 transition"
                />

                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
                  <div className="flex items-center gap-2">
                    <span
                      className={`text-[11px] font-mono px-2 py-0.5 rounded border ${
                        bulkLineCount > 700
                          ? 'bg-rose-500/10 text-rose-400 border-rose-500/30'
                          : 'bg-slate-800 text-slate-400 border-slate-700'
                      }`}
                    >
                      {bulkLineCount} / 700 keywords
                    </span>
                    <span className="text-[11px] text-slate-500">
                      Cached keywords load instantly ($0 spend); misses batched in chunks of 700.
                    </span>
                  </div>

                  <div className="flex items-center gap-2">
                    <select
                      value={locationCode}
                      onChange={(e) => setLocationCode(Number(e.target.value))}
                      disabled={loading}
                      className="rounded-xl bg-slate-950 border border-slate-800 px-3 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-sky-500 transition"
                    >
                      {COMMON_LOCATIONS.map((loc) => (
                        <option key={loc.code} value={loc.code}>
                          {loc.label}
                        </option>
                      ))}
                    </select>

                    <select
                      value={languageCode}
                      onChange={(e) => setLanguageCode(e.target.value)}
                      disabled={loading}
                      className="rounded-xl bg-slate-950 border border-slate-800 px-3 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-sky-500 transition"
                    >
                      {COMMON_LANGUAGES.map((lang) => (
                        <option key={lang.code} value={lang.code}>
                          {lang.label}
                        </option>
                      ))}
                    </select>

                    <button
                      type="submit"
                      disabled={loading || bulkLineCount === 0 || bulkLineCount > 700}
                      className="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-xl bg-sky-500 hover:bg-sky-400 disabled:opacity-50 disabled:cursor-not-allowed text-slate-950 font-semibold text-xs transition shadow-lg shadow-sky-500/10 cursor-pointer"
                    >
                      {loading ? (
                        <>
                          <Loader2 className="h-3.5 w-3.5 animate-spin" />
                          <span>Batching...</span>
                        </>
                      ) : (
                        <>
                          <Layers className="h-3.5 w-3.5" />
                          <span>Analyze Keywords</span>
                        </>
                      )}
                    </button>
                  </div>
                </div>
              </div>
            </form>
          )}
        </div>

        {/* Summary Metrics Banner */}
        {summary && (
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
            <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
              <span className="text-slate-400 text-xs font-medium">Keywords Analyzed</span>
              <div className="mt-1 text-xl font-bold text-white font-mono">
                {summary.total_keywords}
              </div>
              <span className="text-[11px] text-slate-500">Total in batch</span>
            </div>

            <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
              <span className="text-slate-400 text-xs font-medium">Total Search Volume</span>
              <div className="mt-1 text-xl font-bold text-sky-400 font-mono">
                {formatNumber(summary.total_volume)}
              </div>
              <span className="text-[11px] text-slate-500">Monthly searches</span>
            </div>

            <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
              <span className="text-slate-400 text-xs font-medium">Average Difficulty</span>
              <div className="mt-1 text-xl font-bold text-amber-400 font-mono">
                {summary.avg_difficulty !== null ? summary.avg_difficulty : '-'}
              </div>
              <span className="text-[11px] text-slate-500">0 - 100 KD score</span>
            </div>

            <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
              <span className="text-slate-400 text-xs font-medium flex items-center gap-1">
                <Zap className="h-3.5 w-3.5 text-emerald-400" /> Cache Efficiency
              </span>
              <div className="mt-1 text-xl font-bold text-emerald-400 font-mono">
                {summary.cached_count} / {summary.total_keywords}
              </div>
              <span className="text-[11px] text-slate-500">
                {summary.cached_count > 0
                  ? `${Math.round((summary.cached_count / summary.total_keywords) * 100)}% served from cache ($0)`
                  : 'New DataForSEO queries'}
              </span>
            </div>

            <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5 col-span-2 sm:col-span-1">
              <span className="text-slate-400 text-xs font-medium">Intent Breakdown</span>
              <div className="mt-2 flex items-center gap-1 h-3 rounded-full overflow-hidden bg-slate-800">
                {summary.total_keywords > 0 && (
                  <>
                    <div
                      className="bg-sky-500 h-full"
                      style={{
                        width: `${(summary.intent_breakdown.informational / summary.total_keywords) * 100}%`,
                      }}
                      title={`Informational: ${summary.intent_breakdown.informational}`}
                    />
                    <div
                      className="bg-purple-500 h-full"
                      style={{
                        width: `${(summary.intent_breakdown.commercial / summary.total_keywords) * 100}%`,
                      }}
                      title={`Commercial: ${summary.intent_breakdown.commercial}`}
                    />
                    <div
                      className="bg-emerald-500 h-full"
                      style={{
                        width: `${(summary.intent_breakdown.transactional / summary.total_keywords) * 100}%`,
                      }}
                      title={`Transactional: ${summary.intent_breakdown.transactional}`}
                    />
                    <div
                      className="bg-amber-500 h-full"
                      style={{
                        width: `${(summary.intent_breakdown.navigational / summary.total_keywords) * 100}%`,
                      }}
                      title={`Navigational: ${summary.intent_breakdown.navigational}`}
                    />
                  </>
                )}
              </div>
              <div className="mt-1.5 flex items-center justify-between text-[10px] text-slate-400">
                <span className="text-sky-400">Info: {summary.intent_breakdown.informational}</span>
                <span className="text-purple-400">Comm: {summary.intent_breakdown.commercial}</span>
                <span className="text-emerald-400">Trans: {summary.intent_breakdown.transactional}</span>
              </div>
            </div>
          </div>
        )}

        {/* Toolbar & Filters (when results are loaded) */}
        {results.length > 0 && (
          <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3 bg-slate-900/40 border border-slate-800 p-3 rounded-xl">
            <div className="flex flex-wrap items-center gap-2 flex-1">
              {/* Quick Search Filter */}
              <div className="relative min-w-[200px] flex-1 max-w-xs">
                <input
                  type="text"
                  value={searchFilter}
                  onChange={(e) => setSearchFilter(e.target.value)}
                  placeholder="Filter keywords..."
                  className="w-full rounded-lg bg-slate-950 border border-slate-800 pl-8 pr-3 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-sky-500 transition"
                />
                <Search className="h-3.5 w-3.5 text-slate-500 absolute left-2.5 top-2 pointer-events-none" />
              </div>

              {/* Intent Filter */}
              <select
                value={intentFilter}
                onChange={(e) => setIntentFilter(e.target.value)}
                className="rounded-lg bg-slate-950 border border-slate-800 px-2.5 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-sky-500 transition"
              >
                <option value="all">All Intents</option>
                <option value="informational">Informational</option>
                <option value="commercial">Commercial</option>
                <option value="transactional">Transactional</option>
                <option value="navigational">Navigational</option>
              </select>

              {/* KD Filter */}
              <select
                value={kdFilter}
                onChange={(e) => setKdFilter(e.target.value)}
                className="rounded-lg bg-slate-950 border border-slate-800 px-2.5 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-sky-500 transition"
              >
                <option value="all">All Difficulty</option>
                <option value="easy">Easy (&lt; 30)</option>
                <option value="medium">Medium (30 - 69)</option>
                <option value="hard">Hard (&ge; 70)</option>
              </select>

              {/* Volume Filter */}
              <select
                value={volumeFilter}
                onChange={(e) => setVolumeFilter(e.target.value)}
                className="rounded-lg bg-slate-950 border border-slate-800 px-2.5 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-sky-500 transition"
              >
                <option value="all">All Volumes</option>
                <option value="100">&ge; 100/mo</option>
                <option value="1000">&ge; 1,000/mo</option>
                <option value="10000">&ge; 10,000/mo</option>
              </select>

              {/* Sort Dropdown */}
              <select
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value as any)}
                className="rounded-lg bg-slate-950 border border-slate-800 px-2.5 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-sky-500 transition"
              >
                <option value="volume">Sort: Volume (High to Low)</option>
                <option value="kd_asc">Sort: KD (Easy first)</option>
                <option value="kd_desc">Sort: KD (Hard first)</option>
                <option value="cpc">Sort: CPC (High to Low)</option>
                <option value="alpha">Sort: Keyword (A to Z)</option>
              </select>
            </div>

            <div className="flex items-center gap-2 shrink-0">
              {selectedKeywords.size > 0 && (
                <button
                  type="button"
                  onClick={openTrackSelected}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition shadow-md shadow-emerald-500/10 cursor-pointer"
                >
                  <Plus className="h-3.5 w-3.5" />
                  <span>Track Selected ({selectedKeywords.size})</span>
                </button>
              )}

              <button
                type="button"
                onClick={handleExportCsv}
                className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer"
                title="Export currently filtered or selected keywords to CSV"
              >
                <Download className="h-3.5 w-3.5 text-slate-400" />
                <span>Export CSV</span>
              </button>
            </div>
          </div>
        )}

        {/* Results Table */}
        {results.length > 0 ? (
          <div className="rounded-2xl border border-slate-800 bg-slate-900/40 overflow-hidden shadow-xl">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs text-slate-300">
                <thead className="bg-slate-950/80 border-b border-slate-800 text-[11px] font-semibold text-slate-400 uppercase tracking-wider">
                  <tr>
                    <th className="py-3 px-3 w-8">
                      <input
                        type="checkbox"
                        checked={
                          selectedKeywords.size === filteredKeywords.length &&
                          filteredKeywords.length > 0
                        }
                        onChange={toggleSelectAll}
                        className="rounded border-slate-700 bg-slate-900 text-sky-500 focus:ring-sky-500"
                      />
                    </th>
                    <th className="py-3 px-3">Keyword</th>
                    <th className="py-3 px-3 text-right">Search Volume</th>
                    <th className="py-3 px-3 text-center">12M Trend</th>
                    <th className="py-3 px-3 text-left">Difficulty (KD)</th>
                    <th className="py-3 px-3">Search Intent</th>
                    <th className="py-3 px-3 text-right">CPC</th>
                    <th className="py-3 px-3 text-center">Competition</th>
                    <th className="py-3 px-3 text-center">Source</th>
                    <th className="py-3 px-3 text-right">Action</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800/60 font-sans">
                  {filteredKeywords.length === 0 ? (
                    <tr>
                      <td colSpan={10} className="py-8 text-center text-slate-500">
                        No keywords match your active filter criteria.
                      </td>
                    </tr>
                  ) : (
                    filteredKeywords.map((item) => {
                      const isTracked = trackedSet.has(item.keyword.toLowerCase());
                      const isSelected = selectedKeywords.has(item.keyword.toLowerCase());

                      return (
                        <tr
                          key={item.keyword}
                          className={`hover:bg-slate-800/30 transition ${
                            isSelected ? 'bg-sky-500/5' : ''
                          }`}
                        >
                          {/* Checkbox */}
                          <td className="py-3 px-3">
                            <input
                              type="checkbox"
                              checked={isSelected}
                              onChange={() => toggleSelectOne(item.keyword)}
                              className="rounded border-slate-700 bg-slate-900 text-sky-500 focus:ring-sky-500 cursor-pointer"
                            />
                          </td>

                          {/* Keyword */}
                          <td className="py-3 px-3">
                            <div className="flex items-center gap-1.5 group">
                              <span className="font-medium text-white">{item.keyword}</span>
                              <button
                                type="button"
                                onClick={() => handleCopy(item.keyword)}
                                className="text-slate-600 hover:text-slate-300 opacity-0 group-hover:opacity-100 transition"
                                title="Copy keyword"
                              >
                                {copiedKeyword === item.keyword ? (
                                  <Check className="h-3 w-3 text-emerald-400" />
                                ) : (
                                  <Copy className="h-3 w-3" />
                                )}
                              </button>
                            </div>
                          </td>

                          {/* Search Volume */}
                          <td className="py-3 px-3 text-right font-mono font-semibold text-slate-100">
                            {formatNumber(item.search_volume)}
                          </td>

                          {/* 12M Trend */}
                          <td className="py-3 px-3 text-center">
                            <div className="inline-flex justify-center">
                              <MiniTrendBars monthlySearches={item.monthly_searches} />
                            </div>
                          </td>

                          {/* Keyword Difficulty */}
                          <td className="py-3 px-3">
                            <DifficultyPill difficulty={item.keyword_difficulty} />
                          </td>

                          {/* Search Intent */}
                          <td className="py-3 px-3">
                            <IntentPill intent={item.main_intent} />
                          </td>

                          {/* CPC */}
                          <td className="py-3 px-3 text-right font-mono text-slate-300">
                            {item.cpc !== null && item.cpc !== undefined
                              ? `$${item.cpc.toFixed(2)}`
                              : '-'}
                          </td>

                          {/* Competition */}
                          <td className="py-3 px-3 text-center">
                            {item.competition_level ? (
                              <span className="text-[10px] uppercase font-mono px-1.5 py-0.5 rounded bg-slate-800 text-slate-400 border border-slate-700">
                                {item.competition_level}
                              </span>
                            ) : item.competition !== null && item.competition !== undefined ? (
                              <span className="text-[11px] font-mono text-slate-400">
                                {item.competition.toFixed(2)}
                              </span>
                            ) : (
                              <span className="text-slate-500">-</span>
                            )}
                          </td>

                          {/* Cached Status */}
                          <td className="py-3 px-3 text-center">
                            {item.cached ? (
                              <span
                                className="inline-flex items-center gap-0.5 text-[10px] text-emerald-400 font-mono"
                                title="Served from shared cache ($0 spend)"
                              >
                                <Zap className="h-2.5 w-2.5" /> Cached
                              </span>
                            ) : (
                              <span
                                className="text-[10px] text-slate-500 font-mono"
                                title="Fetched live from DataForSEO Labs"
                              >
                                Live
                              </span>
                            )}
                          </td>

                          {/* Actions */}
                          <td className="py-3 px-3 text-right">
                            {isTracked ? (
                              <span className="inline-flex items-center gap-1 text-[11px] text-emerald-400 font-medium px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/20">
                                <CheckCircle2 className="h-3 w-3" /> Tracked
                              </span>
                            ) : (
                              <button
                                type="button"
                                onClick={() => openTrackSingle(item)}
                                className="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-slate-800 hover:bg-emerald-500/20 hover:text-emerald-400 hover:border-emerald-500/30 border border-slate-700 text-slate-300 text-[11px] font-medium transition cursor-pointer"
                              >
                                <Plus className="h-3 w-3" />
                                <span>Track</span>
                              </button>
                            )}
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>

            {/* Table Footer */}
            <div className="bg-slate-950/40 border-t border-slate-800 px-4 py-3 flex items-center justify-between text-xs text-slate-500">
              <div>
                Showing <span className="font-medium text-slate-300">{filteredKeywords.length}</span> of{' '}
                <span className="font-medium text-slate-300">{results.length}</span> keywords
                {selectedKeywords.size > 0 && (
                  <span className="ml-2 text-sky-400">
                    ({selectedKeywords.size} selected)
                  </span>
                )}
              </div>
              <div className="flex items-center gap-2">
                <span>Location: {locationCode}</span>
                <span>•</span>
                <span>Language: {languageCode.toUpperCase()}</span>
              </div>
            </div>
          </div>
        ) : (
          /* Empty State Before Search */
          <div className="rounded-2xl border border-slate-800/80 bg-slate-900/20 p-12 text-center space-y-4">
            <div className="h-12 w-12 rounded-2xl bg-sky-500/10 border border-sky-500/20 flex items-center justify-center mx-auto text-sky-400 shadow-inner">
              <Sparkles className="h-6 w-6" />
            </div>
            <div className="max-w-md mx-auto">
              <h3 className="text-base font-semibold text-white">
                Discover keywords & verify search volume
              </h3>
              <p className="mt-1 text-xs text-slate-400">
                Explore search queries, 12-month historical trends, keyword difficulty, and search intent tags. Add promising keywords directly to your Rank Tracker in one click.
              </p>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 max-w-xl mx-auto pt-4 text-left">
              <div className="rounded-xl border border-slate-800 bg-slate-900/50 p-3.5 space-y-1">
                <div className="text-xs font-semibold text-slate-200 flex items-center gap-1.5">
                  <Zap className="h-3.5 w-3.5 text-emerald-400" />
                  <span>30-Day Shared Cache</span>
                </div>
                <p className="text-[11px] text-slate-500">
                  Keywords analyzed by any workspace are cached for 30 days. Re-querying cached keywords costs $0.
                </p>
              </div>

              <div className="rounded-xl border border-slate-800 bg-slate-900/50 p-3.5 space-y-1">
                <div className="text-xs font-semibold text-slate-200 flex items-center gap-1.5">
                  <Layers className="h-3.5 w-3.5 text-sky-400" />
                  <span>700-Keyword Batching</span>
                </div>
                <p className="text-[11px] text-slate-500">
                  Bulk analyze up to 700 keywords in a single DataForSEO Labs request at wholesale unit rates.
                </p>
              </div>

              <div className="rounded-xl border border-slate-800 bg-slate-900/50 p-3.5 space-y-1">
                <div className="text-xs font-semibold text-slate-200 flex items-center gap-1.5">
                  <Search className="h-3.5 w-3.5 text-amber-400" />
                  <span>Rank Tracker Sync</span>
                </div>
                <p className="text-[11px] text-slate-500">
                  One-click track pushes volume and KD directly to your project's multi-device SERP monitor.
                </p>
              </div>
            </div>
          </div>
        )}

        {/* Add to Rank Tracker Modal */}
        {trackModalOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-xs p-4">
            <div className="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-6 shadow-2xl space-y-5">
              <div className="flex items-center justify-between border-b border-slate-800 pb-3">
                <div className="flex items-center gap-2">
                  <Search className="h-4 w-4 text-emerald-400" />
                  <h3 className="text-base font-semibold text-white">
                    Add to Rank Tracker
                  </h3>
                </div>
                <button
                  type="button"
                  onClick={() => setTrackModalOpen(false)}
                  disabled={trackingLoading}
                  className="text-slate-400 hover:text-white"
                >
                  <X className="h-4 w-4" />
                </button>
              </div>

              <div className="space-y-4 text-xs">
                <div>
                  <label className="block text-slate-400 mb-1 font-medium">
                    Keywords to Track ({keywordsToTrack.length})
                  </label>
                  <div className="max-h-28 overflow-y-auto rounded-lg bg-slate-950 border border-slate-800 p-2.5 font-mono text-[11px] text-slate-300 space-y-1">
                    {keywordsToTrack.map((k) => (
                      <div key={k.keyword} className="flex items-center justify-between">
                        <span>{k.keyword}</span>
                        <span className="text-slate-500">
                          Vol: {formatNumber(k.search_volume)} • KD: {k.keyword_difficulty ?? '-'}
                        </span>
                      </div>
                    ))}
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label className="block text-slate-400 mb-1 font-medium">Device</label>
                    <div className="grid grid-cols-2 gap-1 rounded-lg bg-slate-950 p-1 border border-slate-800">
                      <button
                        type="button"
                        onClick={() => setTrackDevice('desktop')}
                        className={`flex items-center justify-center gap-1.5 py-1.5 rounded text-xs font-medium transition cursor-pointer ${
                          trackDevice === 'desktop'
                            ? 'bg-slate-800 text-white shadow-xs'
                            : 'text-slate-400 hover:text-white'
                        }`}
                      >
                        <Laptop className="h-3 w-3" /> Desktop
                      </button>
                      <button
                        type="button"
                        onClick={() => setTrackDevice('mobile')}
                        className={`flex items-center justify-center gap-1.5 py-1.5 rounded text-xs font-medium transition cursor-pointer ${
                          trackDevice === 'mobile'
                            ? 'bg-slate-800 text-white shadow-xs'
                            : 'text-slate-400 hover:text-white'
                        }`}
                      >
                        <Smartphone className="h-3 w-3" /> Mobile
                      </button>
                    </div>
                  </div>

                  <div>
                    <label className="block text-slate-400 mb-1 font-medium">Check Cadence</label>
                    <div className="grid grid-cols-2 gap-1 rounded-lg bg-slate-950 p-1 border border-slate-800">
                      <button
                        type="button"
                        onClick={() => setTrackCadence('weekly')}
                        className={`py-1.5 rounded text-xs font-medium transition cursor-pointer ${
                          trackCadence === 'weekly'
                            ? 'bg-slate-800 text-white shadow-xs'
                            : 'text-slate-400 hover:text-white'
                        }`}
                      >
                        Weekly
                      </button>
                      <button
                        type="button"
                        onClick={() => setTrackCadence('daily')}
                        className={`py-1.5 rounded text-xs font-medium transition cursor-pointer ${
                          trackCadence === 'daily'
                            ? 'bg-slate-800 text-white shadow-xs'
                            : 'text-slate-400 hover:text-white'
                        }`}
                      >
                        Daily
                      </button>
                    </div>
                  </div>
                </div>

                <div className="rounded-lg bg-slate-950/60 border border-slate-800 p-2.5 text-[11px] text-slate-400">
                  Target Project: <span className="text-white font-mono">{project.domain}</span>
                  <br />
                  Market:{' '}
                  <span className="text-white font-mono">
                    {Array.from(
                      new Set(
                        keywordsToTrack.map(
                          (k) => `LOC:${k.location_code} (${k.language_code.toUpperCase()})`
                        )
                      )
                    ).join(', ')}
                  </span>
                </div>
              </div>

              <div className="flex items-center justify-end gap-2 border-t border-slate-800 pt-4">
                <button
                  type="button"
                  onClick={() => setTrackModalOpen(false)}
                  disabled={trackingLoading}
                  className="px-3.5 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-medium transition cursor-pointer"
                >
                  Cancel
                </button>
                <button
                  type="button"
                  onClick={submitTrackKeywords}
                  disabled={trackingLoading}
                  className="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition shadow-lg shadow-emerald-500/10 cursor-pointer"
                >
                  {trackingLoading ? (
                    <>
                      <Loader2 className="h-3.5 w-3.5 animate-spin" />
                      <span>Adding to Monitor...</span>
                    </>
                  ) : (
                    <>
                      <Plus className="h-3.5 w-3.5" />
                      <span>Confirm & Track ({keywordsToTrack.length})</span>
                    </>
                  )}
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
