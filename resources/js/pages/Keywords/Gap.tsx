import { Head, Link } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowLeft,
  Check,
  CheckCircle2,
  Copy,
  Download,
  ExternalLink,
  Laptop,
  Layers,
  Lightbulb,
  Loader2,
  Plus,
  Search,
  Smartphone,
  Sparkles,
  TrendingUp,
  Users,
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

interface ProjectCompetitor {
  id: number;
  domain: string;
  label: string | null;
  sort_order: number;
}

interface MonthlySearch {
  year: number;
  month: number;
  search_volume: number;
}

interface CompetitorRank {
  domain: string;
  rank: number | null;
  url: string | null;
}

interface GapKeywordItem {
  keyword: string;
  location_code: number;
  language_code: string;
  search_volume: number | null;
  cpc: number | null;
  competition: number | null;
  competition_level: string | null;
  keyword_difficulty: number | null;
  main_intent: string | null;
  monthly_searches: MonthlySearch[];
  project_rank: number | null;
  project_url: string | null;
  competitor_ranks: Record<string, CompetitorRank>;
  best_competitor_rank: number | null;
  gap_type: 'missing' | 'untapped' | 'weak' | 'shared' | 'strong';
  opportunity_score: number;
}

interface GapSummary {
  total_keywords: number;
  missing_count: number;
  weak_count: number;
  shared_count: number;
  strong_count: number;
  total_volume: number;
  avg_difficulty: number | null;
  cached_tasks_count: number;
  total_tasks_count: number;
}

interface KeywordGapProps {
  workspace: WorkspaceSummary;
  project: ProjectDetails;
  competitors: ProjectCompetitor[];
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

function makeTrackedKey(
  keyword: string,
  device: string,
  locationCode: number,
  languageCode: string
): string {
  return `${keyword.toLowerCase().trim()}:${device.toLowerCase().trim()}:${locationCode}:${languageCode.toLowerCase().trim()}`;
}

function MiniTrendBars({ monthlySearches }: { monthlySearches: MonthlySearch[] }) {
  if (!monthlySearches || monthlySearches.length === 0) {
    return <span className="text-[11px] text-slate-600">-</span>;
  }

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

function OpportunityBadge({ score }: { score: number }) {
  let color = 'bg-slate-500/10 text-slate-400 border-slate-500/20';
  if (score >= 70) {
    color = 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30 font-bold';
  } else if (score >= 40) {
    color = 'bg-amber-500/15 text-amber-400 border-amber-500/30 font-semibold';
  }

  return (
    <span
      className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-mono border ${color}`}
      title={`Opportunity Score: ${score}/100 (Combines search volume, competitor rank, and difficulty)`}
    >
      <Sparkles className="h-2.5 w-2.5" />
      <span>{score}</span>
    </span>
  );
}

function GapTypePill({ type }: { type: GapKeywordItem['gap_type'] }) {
  switch (type) {
    case 'missing':
      return (
        <span
          className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-500/15 text-rose-400 border border-rose-500/30"
          title="Competitor ranks in Top 20 while your project domain does not rank"
        >
          Missing
        </span>
      );
    case 'weak':
      return (
        <span
          className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-500/15 text-amber-400 border border-amber-500/30"
          title="Your project ranks, but one or more competitors outrank you"
        >
          Weak
        </span>
      );
    case 'shared':
      return (
        <span
          className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-sky-500/15 text-sky-400 border border-sky-500/30"
          title="Both your project and competitor rank at the same position"
        >
          Shared
        </span>
      );
    case 'strong':
      return (
        <span
          className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30"
          title="Your project outranks all selected competitors"
        >
          Strong
        </span>
      );
    case 'untapped':
    default:
      return (
        <span
          className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-slate-500/15 text-slate-400 border border-slate-500/30"
          title="Competitor ranks beyond Top 20; your project does not rank"
        >
          Untapped
        </span>
      );
  }
}

function DifficultyPill({ difficulty }: { difficulty: number | null | undefined }) {
  if (difficulty === null || difficulty === undefined) {
    return <span className="text-slate-500 text-xs">-</span>;
  }

  let colorClasses = 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30';
  let label = 'Easy';

  if (difficulty >= 70) {
    colorClasses = 'bg-red-500/15 text-red-400 border-red-500/30';
    label = 'Hard';
  } else if (difficulty >= 30) {
    colorClasses = 'bg-amber-500/15 text-amber-400 border-amber-500/30';
    label = 'Medium';
  }

  return (
    <span
      className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-mono border ${colorClasses}`}
      title={`Keyword Difficulty: ${difficulty}/100 (${label})`}
    >
      <span>{difficulty}</span>
      <span className="text-[10px] opacity-75">{label}</span>
    </span>
  );
}

function IntentPill({ intent }: { intent: string | null | undefined }) {
  if (!intent) return <span className="text-slate-500 text-xs">-</span>;

  const map: Record<string, { label: string; cls: string }> = {
    informational: { label: 'Info', cls: 'bg-sky-500/10 text-sky-400 border-sky-500/20' },
    commercial: { label: 'Comm', cls: 'bg-purple-500/10 text-purple-400 border-purple-500/20' },
    transactional: { label: 'Trans', cls: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' },
    navigational: { label: 'Nav', cls: 'bg-amber-500/10 text-amber-400 border-amber-500/20' },
  };

  const item = map[intent.toLowerCase()] ?? {
    label: intent,
    cls: 'bg-slate-500/10 text-slate-400 border-slate-500/20',
  };

  return (
    <span className={`inline-block px-2 py-0.5 rounded-full text-[10px] font-medium border ${item.cls}`}>
      {item.label}
    </span>
  );
}

export default function KeywordGap({
  workspace,
  project,
  competitors,
  trackedKeywords,
  defaultLocationCode,
  defaultLanguageCode,
}: KeywordGapProps) {
  // Search parameters
  const [selectedCompetitors, setSelectedCompetitors] = useState<string[]>(() => {
    return competitors.slice(0, 2).map((c) => c.domain);
  });
  const [customCompetitorInput, setCustomCompetitorInput] = useState('');
  const [locationCode, setLocationCode] = useState<number>(defaultLocationCode);
  const [languageCode, setLanguageCode] = useState<string>(defaultLanguageCode);
  const [activeTab, setActiveTab] = useState<'all' | 'missing' | 'weak' | 'shared' | 'strong'>('all');

  // Async results & summary
  const [results, setResults] = useState<GapKeywordItem[]>([]);
  const [summary, setSummary] = useState<GapSummary | null>(null);
  const [searchedCompetitors, setSearchedCompetitors] = useState<string[]>([]);
  const [loading, setLoading] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [successMsg, setSuccessMsg] = useState<string | null>(null);

  // Table selection & filters
  const [selectedKeywords, setSelectedKeywords] = useState<Set<string>>(new Set());
  const [searchFilter, setSearchFilter] = useState('');
  const [intentFilter, setIntentFilter] = useState('all');
  const [kdFilter, setKdFilter] = useState('all');
  const [volumeFilter, setVolumeFilter] = useState('all');
  const [sortBy, setSortBy] = useState<
    'opportunity' | 'volume' | 'comp_rank' | 'project_rank' | 'kd_asc' | 'alpha'
  >('opportunity');
  const [copiedKeyword, setCopiedKeyword] = useState<string | null>(null);

  // Tracking state & modal
  const [trackedSet, setTrackedSet] = useState<Set<string>>(() => new Set(trackedKeywords));
  const [trackModalOpen, setTrackModalOpen] = useState(false);
  const [keywordsToTrack, setKeywordsToTrack] = useState<GapKeywordItem[]>([]);
  const [trackDevice, setTrackDevice] = useState<'desktop' | 'mobile'>('desktop');
  const [trackCadence, setTrackCadence] = useState<'weekly' | 'daily'>('weekly');
  const [trackingLoading, setTrackingLoading] = useState(false);

  // Competitor selection toggling (1 to 3 competitors)
  const toggleCompetitor = (domain: string) => {
    const norm = domain.toLowerCase().trim();
    if (selectedCompetitors.includes(norm)) {
      if (selectedCompetitors.length === 1) return; // Keep at least one
      setSelectedCompetitors(selectedCompetitors.filter((d) => d !== norm));
    } else {
      if (selectedCompetitors.length >= 3) return; // Maximum 3 competitors
      setSelectedCompetitors([...selectedCompetitors, norm]);
    }
  };

  const removeCustomCompetitor = (domain: string) => {
    const norm = domain.toLowerCase().trim();
    setSelectedCompetitors(selectedCompetitors.filter((d) => d !== norm));
  };

  const addCustomCompetitor = () => {
    const raw = customCompetitorInput.trim().toLowerCase();
    if (!raw) return;
    const clean = raw.replace(/^https?:\/\//, '').replace(/\/.*$/, '').replace(/^www\./, '');
    if (!clean || clean === project.domain.toLowerCase()) return;
    if (!selectedCompetitors.includes(clean)) {
      if (selectedCompetitors.length >= 3) {
        setErrorMsg('You can compare against at most 3 competitors simultaneously.');
        return;
      }
      setSelectedCompetitors([...selectedCompetitors, clean]);
    }
    setCustomCompetitorInput('');
  };

  // Run Gap Analysis
  const handleSearch = async (e?: React.FormEvent) => {
    if (e) e.preventDefault();
    if (selectedCompetitors.length === 0) {
      setErrorMsg('Please select at least 1 competitor domain.');
      return;
    }

    setLoading(true);
    setErrorMsg(null);
    setSuccessMsg(null);

    try {
      const response = await fetch(
        `/workspaces/${workspace.id}/projects/${project.id}/keyword-gap/search`,
        {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...getCsrfHeaders(),
          },
          body: JSON.stringify({
            competitors: selectedCompetitors,
            location_code: locationCode,
            language_code: languageCode,
            gap_mode: 'all',
          }),
        }
      );

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Failed to analyze keyword gaps.');
      }

      setResults(data.keywords || []);
      setSummary(data.summary || null);
      setSearchedCompetitors(data.competitors || selectedCompetitors);
      setSelectedKeywords(new Set());
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'An unexpected error occurred.';
      setErrorMsg(message);
    } finally {
      setLoading(false);
    }
  };

  const handleCopy = (kw: string) => {
    navigator.clipboard.writeText(kw);
    setCopiedKeyword(kw);
    setTimeout(() => setCopiedKeyword(null), 2000);
  };

  const openTrackSingle = (item: GapKeywordItem, preferredDevice?: 'desktop' | 'mobile') => {
    if (preferredDevice) {
      setTrackDevice(preferredDevice);
    } else {
      const isDesktop = trackedSet.has(
        makeTrackedKey(item.keyword, 'desktop', item.location_code, item.language_code)
      );
      const isMobile = trackedSet.has(
        makeTrackedKey(item.keyword, 'mobile', item.location_code, item.language_code)
      );
      if (isDesktop && !isMobile) {
        setTrackDevice('mobile');
      } else if (isMobile && !isDesktop) {
        setTrackDevice('desktop');
      }
    }
    setKeywordsToTrack([item]);
    setTrackModalOpen(true);
  };

  // Filtered & sorted keywords
  const filteredKeywords = useMemo(() => {
    let list = results;

    // Filter by Tab (Missing, Weak, Shared, Strong)
    if (activeTab !== 'all') {
      if (activeTab === 'missing') {
        list = list.filter((item) => item.gap_type === 'missing' || item.gap_type === 'untapped');
      } else {
        list = list.filter((item) => item.gap_type === activeTab);
      }
    }

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
      list = list.filter((item) => item.keyword_difficulty !== null && item.keyword_difficulty >= 70);
    }

    if (volumeFilter !== 'all') {
      const minVol = parseInt(volumeFilter, 10);
      list = list.filter((item) => (item.search_volume ?? 0) >= minVol);
    }

    return [...list].sort((a, b) => {
      if (sortBy === 'opportunity') {
        if (b.opportunity_score !== a.opportunity_score) {
          return b.opportunity_score - a.opportunity_score;
        }
        return (b.search_volume ?? 0) - (a.search_volume ?? 0);
      }
      if (sortBy === 'volume') {
        return (b.search_volume ?? 0) - (a.search_volume ?? 0);
      }
      if (sortBy === 'comp_rank') {
        return (a.best_competitor_rank ?? 999) - (b.best_competitor_rank ?? 999);
      }
      if (sortBy === 'project_rank') {
        return (a.project_rank ?? 999) - (b.project_rank ?? 999);
      }
      if (sortBy === 'kd_asc') {
        return (a.keyword_difficulty ?? 999) - (b.keyword_difficulty ?? 999);
      }
      if (sortBy === 'alpha') {
        return a.keyword.localeCompare(b.keyword);
      }
      return 0;
    });
  }, [results, activeTab, searchFilter, intentFilter, kdFilter, volumeFilter, sortBy]);

  const selectedUntrackedCount = useMemo(() => {
    return filteredKeywords.filter((k) => {
      const isSelected = selectedKeywords.has(k.keyword.toLowerCase());
      const isFullyTracked =
        trackedSet.has(makeTrackedKey(k.keyword, 'desktop', k.location_code, k.language_code)) &&
        trackedSet.has(makeTrackedKey(k.keyword, 'mobile', k.location_code, k.language_code));
      return isSelected && !isFullyTracked;
    }).length;
  }, [filteredKeywords, selectedKeywords, trackedSet]);

  const filteredUntrackedCount = useMemo(() => {
    return filteredKeywords.filter((k) => {
      const isFullyTracked =
        trackedSet.has(makeTrackedKey(k.keyword, 'desktop', k.location_code, k.language_code)) &&
        trackedSet.has(makeTrackedKey(k.keyword, 'mobile', k.location_code, k.language_code));
      return !isFullyTracked;
    }).length;
  }, [filteredKeywords, trackedSet]);

  const toggleSelectAll = () => {
    const untracked = filteredKeywords
      .filter((k) => {
        const isFullyTracked =
          trackedSet.has(makeTrackedKey(k.keyword, 'desktop', k.location_code, k.language_code)) &&
          trackedSet.has(makeTrackedKey(k.keyword, 'mobile', k.location_code, k.language_code));
        return !isFullyTracked;
      })
      .map((k) => k.keyword.toLowerCase());

    if (untracked.length === 0) return;

    const allUntrackedSelected = untracked.every((k) => selectedKeywords.has(k));
    if (allUntrackedSelected) {
      setSelectedKeywords(new Set());
    } else {
      setSelectedKeywords(new Set(untracked));
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

  const openTrackSelected = () => {
    const items = filteredKeywords.filter((r) => {
      const isSelected = selectedKeywords.has(r.keyword.toLowerCase());
      const isFullyTracked =
        trackedSet.has(makeTrackedKey(r.keyword, 'desktop', r.location_code, r.language_code)) &&
        trackedSet.has(makeTrackedKey(r.keyword, 'mobile', r.location_code, r.language_code));
      return isSelected && !isFullyTracked;
    });
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
          `/workspaces/${workspace.id}/projects/${project.id}/keyword-gap/track`,
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

      setTrackedSet((prev) => {
        const next = new Set(prev);
        keywordsToTrack.forEach((k) => {
          next.add(makeTrackedKey(k.keyword, trackDevice, k.location_code, k.language_code));
        });
        return next;
      });

      setSelectedKeywords(new Set());
      setTrackModalOpen(false);
      setSuccessMsg(
        `Added ${totalAdded} keyword gap(s) to Rank Tracker for ${trackDevice} monitoring!`
      );
    } catch (err: unknown) {
      const message = err instanceof Error ? err.message : 'Failed to track keywords.';
      setErrorMsg(message);
    } finally {
      setTrackingLoading(false);
    }
  };

  const handleExportCsv = () => {
    const itemsToExport =
      selectedKeywords.size > 0
        ? filteredKeywords.filter((k) => selectedKeywords.has(k.keyword.toLowerCase()))
        : filteredKeywords;

    if (itemsToExport.length === 0) return;

    const competitorHeaders = searchedCompetitors.map((c) => `Rank: ${c}`);
    const headers = [
      'Keyword',
      'Opportunity Score',
      'Gap Type',
      'Search Volume',
      `Your Rank (${project.domain})`,
      ...competitorHeaders,
      'Keyword Difficulty',
      'Search Intent',
      'CPC (USD)',
      'Tracked',
    ];

    const rows = itemsToExport.map((item) => {
      const isDesktop = trackedSet.has(
        makeTrackedKey(item.keyword, 'desktop', item.location_code, item.language_code)
      );
      const isMobile = trackedSet.has(
        makeTrackedKey(item.keyword, 'mobile', item.location_code, item.language_code)
      );
      const trackedStatus =
        isDesktop && isMobile
          ? 'Desktop & Mobile'
          : isDesktop
          ? 'Desktop'
          : isMobile
          ? 'Mobile'
          : 'No';

      const compRankCols = searchedCompetitors.map((c) => {
        const r = item.competitor_ranks[c]?.rank;
        return r !== null && r !== undefined ? r : '';
      });

      return [
        `"${item.keyword.replace(/"/g, '""')}"`,
        item.opportunity_score,
        item.gap_type.toUpperCase(),
        item.search_volume ?? '',
        item.project_rank ?? '',
        ...compRankCols,
        item.keyword_difficulty ?? '',
        item.main_intent ?? '',
        item.cpc ? item.cpc.toFixed(2) : '',
        trackedStatus,
      ];
    });

    const csvContent = [headers.join(','), ...rows.map((r) => r.join(','))].join('\r\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute(
      'download',
      `keyword-gap-${project.domain}-${new Date().toISOString().slice(0, 10)}.csv`
    );
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  };

  return (
    <AppLayout currentWorkspace={workspace}>
      <Head title={`${project.name} - Competitor Keyword Gap & Domain Intersection`} />

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
          <span className="text-white font-medium">Keyword Gap</span>
        </div>

        {/* Page Header */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-slate-800 pb-6">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
                <Users className="h-6 w-6 text-sky-400" />
                <span>Competitor Keyword Gap</span>
              </h1>
              <span className="rounded-full bg-sky-500/10 border border-sky-500/20 px-2.5 py-0.5 text-xs font-mono text-sky-400">
                14-Day Pair Caching
              </span>
            </div>
            <p className="mt-1 text-xs text-slate-400">
              Identify high-opportunity keywords where competitors rank in the top 20 while{' '}
              <span className="text-slate-200 font-mono font-medium">{project.domain}</span> is absent
              or outranked.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <Link
              href={`/workspaces/${workspace.id}/projects/${project.id}/keyword-explorer`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer"
            >
              <Sparkles className="h-3.5 w-3.5 text-sky-400" />
              <span>Keyword Explorer</span>
            </Link>

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

        {/* Competitor Selection & Analysis Setup Panel */}
        <div className="rounded-2xl border border-slate-800 bg-slate-900/60 p-5 space-y-4 shadow-xl">
          <form onSubmit={handleSearch} className="space-y-4">
            <div>
              <label className="block text-xs font-medium text-slate-300 mb-2">
                Select Competitors to Compare (1 to 3) against{' '}
                <span className="text-white font-mono font-semibold">{project.domain}</span>:
              </label>

              {/* Registered Competitors Badges */}
              <div className="flex flex-wrap items-center gap-2">
                {competitors.map((comp) => {
                  const isSelected = selectedCompetitors.includes(comp.domain.toLowerCase());
                  return (
                    <button
                      key={comp.id}
                      type="button"
                      onClick={() => toggleCompetitor(comp.domain)}
                      className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-medium transition cursor-pointer border ${
                        isSelected
                          ? 'bg-sky-500/20 text-sky-300 border-sky-500/40 shadow-xs'
                          : 'bg-slate-950 text-slate-400 border-slate-800 hover:border-slate-700 hover:text-slate-200'
                      }`}
                    >
                      <span>{comp.label || comp.domain}</span>
                      {isSelected ? (
                        <Check className="h-3 w-3 text-sky-400" />
                      ) : (
                        <Plus className="h-3 w-3 text-slate-600" />
                      )}
                    </button>
                  );
                })}

                {/* Custom Added Competitors */}
                {selectedCompetitors
                  .filter((d) => !competitors.some((c) => c.domain.toLowerCase() === d))
                  .map((customDomain) => (
                    <span
                      key={customDomain}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-medium bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 shadow-xs"
                    >
                      <span>{customDomain}</span>
                      <button
                        type="button"
                        onClick={() => removeCustomCompetitor(customDomain)}
                        className="text-emerald-400 hover:text-emerald-200 transition cursor-pointer ml-0.5"
                        title={`Remove ${customDomain}`}
                        aria-label={`Remove ${customDomain}`}
                      >
                        <X className="h-3 w-3" />
                      </button>
                    </span>
                  ))}

                {/* Add Custom Competitor Input */}
                <div className="inline-flex items-center rounded-xl bg-slate-950 border border-slate-800 px-2 py-1">
                  <input
                    type="text"
                    value={customCompetitorInput}
                    onChange={(e) => setCustomCompetitorInput(e.target.value)}
                    placeholder="Add competitor domain..."
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        e.preventDefault();
                        addCustomCompetitor();
                      }
                    }}
                    className="bg-transparent text-xs text-white placeholder-slate-500 focus:outline-none w-44"
                  />
                  <button
                    type="button"
                    onClick={addCustomCompetitor}
                    disabled={!customCompetitorInput.trim()}
                    className="text-xs text-sky-400 hover:text-sky-300 disabled:opacity-40 disabled:cursor-not-allowed ml-1"
                  >
                    Add
                  </button>
                </div>
              </div>
            </div>

            {/* Market & Gap Mode Configuration */}
            <div className="grid grid-cols-1 md:grid-cols-12 gap-3 pt-2 border-t border-slate-800/80">
              <div className="md:col-span-4">
                <label className="block text-[11px] font-medium text-slate-400 mb-1">
                  Target Location
                </label>
                <select
                  value={locationCode}
                  onChange={(e) => setLocationCode(parseInt(e.target.value, 10))}
                  disabled={loading}
                  className="w-full rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-sky-500 transition"
                >
                  {COMMON_LOCATIONS.map((loc) => (
                    <option key={loc.code} value={loc.code}>
                      {loc.label}
                    </option>
                  ))}
                </select>
              </div>

              <div className="md:col-span-3">
                <label className="block text-[11px] font-medium text-slate-400 mb-1">Language</label>
                <select
                  value={languageCode}
                  onChange={(e) => setLanguageCode(e.target.value)}
                  disabled={loading}
                  className="w-full rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-sky-500 transition"
                >
                  {COMMON_LANGUAGES.map((lang) => (
                    <option key={lang.code} value={lang.code}>
                      {lang.label}
                    </option>
                  ))}
                </select>
              </div>

              <div className="md:col-span-3">
                <label className="block text-[11px] font-medium text-slate-400 mb-1">
                  Analysis Scope
                </label>
                <select
                  value={activeTab}
                  onChange={(e) => setActiveTab(e.target.value as any)}
                  disabled={loading}
                  className="w-full rounded-xl bg-slate-950 border border-slate-800 px-3 py-2 text-xs text-slate-200 focus:outline-none focus:border-sky-500 transition"
                >
                  <option value="all">All Opportunities</option>
                  <option value="missing">Missing (Competitor in Top 20, You Absent)</option>
                  <option value="weak">Weak (Competitors Outrank You)</option>
                  <option value="shared">Shared (Head-to-head)</option>
                  <option value="strong">Strong (You Outrank Competitors)</option>
                </select>
              </div>

              <div className="md:col-span-2 flex items-end">
                <button
                  type="submit"
                  disabled={loading || selectedCompetitors.length === 0}
                  className="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-semibold px-4 py-2 text-xs transition shadow-lg shadow-sky-500/10 disabled:opacity-50 disabled:cursor-not-allowed cursor-pointer"
                >
                  {loading ? (
                    <>
                      <Loader2 className="h-3.5 w-3.5 animate-spin" />
                      <span>Analyzing...</span>
                    </>
                  ) : (
                    <>
                      <Sparkles className="h-3.5 w-3.5" />
                      <span>Run Analysis</span>
                    </>
                  )}
                </button>
              </div>
            </div>
          </form>
        </div>

        {/* Summary Dashboard Cards (when results exist) */}
        {summary && (
          <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
            <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
              <span className="text-slate-400 text-xs font-medium">Total Opportunities</span>
              <div className="mt-1 text-xl font-bold text-sky-400 font-mono">
                {formatNumber(summary.total_keywords)}
              </div>
              <span className="text-[11px] text-slate-500">
                {formatNumber(summary.total_volume)} total search vol
              </span>
            </div>

            <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
              <span className="text-slate-400 text-xs font-medium flex items-center gap-1">
                <span className="h-2 w-2 rounded-full bg-rose-400" /> Missing Gaps
              </span>
              <div className="mt-1 text-xl font-bold text-rose-400 font-mono">
                {summary.missing_count}
              </div>
              <span className="text-[11px] text-slate-500">Rivals in top 20, you absent</span>
            </div>

            <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
              <span className="text-slate-400 text-xs font-medium flex items-center gap-1">
                <span className="h-2 w-2 rounded-full bg-amber-400" /> Weak Rankings
              </span>
              <div className="mt-1 text-xl font-bold text-amber-400 font-mono">
                {summary.weak_count}
              </div>
              <span className="text-[11px] text-slate-500">Rivals outrank your page</span>
            </div>

            <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
              <span className="text-slate-400 text-xs font-medium flex items-center gap-1">
                <span className="h-2 w-2 rounded-full bg-emerald-400" /> Strong / Shared
              </span>
              <div className="mt-1 text-xl font-bold text-emerald-400 font-mono">
                {summary.strong_count + summary.shared_count}
              </div>
              <span className="text-[11px] text-slate-500">You lead or tie</span>
            </div>

            <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-3.5">
              <span className="text-slate-400 text-xs font-medium flex items-center gap-1">
                <Zap className="h-3.5 w-3.5 text-emerald-400" /> Cache Efficiency
              </span>
              <div className="mt-1 text-xl font-bold text-emerald-400 font-mono">
                {summary.cached_tasks_count} / {summary.total_tasks_count}
              </div>
              <span className="text-[11px] text-slate-500">
                {summary.cached_tasks_count > 0
                  ? `${Math.round((summary.cached_tasks_count / Math.max(1, summary.total_tasks_count)) * 100)}% served from cache ($0)`
                  : '14-day domain cache active'}
              </span>
            </div>
          </div>
        )}

        {/* Tab & Filter Bar (when results exist) */}
        {results.length > 0 && (
          <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-3 bg-slate-900/40 border border-slate-800 p-3 rounded-xl">
            <div className="flex flex-wrap items-center gap-2 flex-1">
              {/* Quick Search Filter */}
              <div className="relative min-w-[180px] flex-1 max-w-xs">
                <input
                  type="text"
                  value={searchFilter}
                  onChange={(e) => setSearchFilter(e.target.value)}
                  placeholder="Filter keywords..."
                  className="w-full rounded-lg bg-slate-950 border border-slate-800 pl-8 pr-3 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-sky-500 transition"
                />
                <Search className="h-3.5 w-3.5 text-slate-500 absolute left-2.5 top-2 pointer-events-none" />
              </div>

              {/* Scope/Tab Filter */}
              <select
                value={activeTab}
                onChange={(e) => setActiveTab(e.target.value as any)}
                className="rounded-lg bg-slate-950 border border-slate-800 px-2.5 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-sky-500 transition"
              >
                <option value="all">All Opportunities ({results.length})</option>
                <option value="missing">Missing Only ({summary?.missing_count ?? 0})</option>
                <option value="weak">Weak Only ({summary?.weak_count ?? 0})</option>
                <option value="shared">Shared ({summary?.shared_count ?? 0})</option>
                <option value="strong">Strong ({summary?.strong_count ?? 0})</option>
              </select>

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
                <option value="opportunity">Sort: Opportunity Score</option>
                <option value="volume">Sort: Search Volume</option>
                <option value="comp_rank">Sort: Best Competitor Rank</option>
                <option value="project_rank">Sort: Your Rank</option>
                <option value="kd_asc">Sort: KD (Easy first)</option>
                <option value="alpha">Sort: Keyword (A to Z)</option>
              </select>
            </div>

            <div className="flex items-center gap-2 shrink-0">
              {selectedUntrackedCount > 0 && (
                <button
                  type="button"
                  onClick={openTrackSelected}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition shadow-md shadow-emerald-500/10 cursor-pointer"
                >
                  <Plus className="h-3.5 w-3.5" />
                  <span>Track Selected ({selectedUntrackedCount})</span>
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
                          selectedUntrackedCount > 0 &&
                          selectedUntrackedCount === filteredUntrackedCount
                        }
                        onChange={toggleSelectAll}
                        disabled={filteredUntrackedCount === 0}
                        className="rounded border-slate-700 bg-slate-900 text-sky-500 focus:ring-sky-500 disabled:opacity-30 disabled:cursor-not-allowed cursor-pointer"
                        title={
                          filteredUntrackedCount === 0
                            ? 'All visible keywords are tracked on both desktop and mobile'
                            : 'Select all keywords with untracked devices'
                        }
                      />
                    </th>
                    <th className="py-3 px-3">Keyword</th>
                    <th className="py-3 px-3 text-center">Opportunity</th>
                    <th className="py-3 px-3 text-center">Gap Type</th>
                    <th className="py-3 px-3 text-right">Search Volume</th>
                    <th className="py-3 px-3 text-center">Your Rank</th>
                    <th className="py-3 px-3">Competitor Ranks</th>
                    <th className="py-3 px-3 text-center">12M Trend</th>
                    <th className="py-3 px-3 text-left">Difficulty (KD)</th>
                    <th className="py-3 px-3">Search Intent</th>
                    <th className="py-3 px-3 text-right">CPC</th>
                    <th className="py-3 px-3 text-right">Action</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-800/60 font-sans">
                  {filteredKeywords.length === 0 ? (
                    <tr>
                      <td colSpan={12} className="py-8 text-center text-slate-500">
                        No keywords match your active filter criteria.
                      </td>
                    </tr>
                  ) : (
                    filteredKeywords.map((item) => {
                      const isDesktopTracked = trackedSet.has(
                        makeTrackedKey(item.keyword, 'desktop', item.location_code, item.language_code)
                      );
                      const isMobileTracked = trackedSet.has(
                        makeTrackedKey(item.keyword, 'mobile', item.location_code, item.language_code)
                      );
                      const isFullyTracked = isDesktopTracked && isMobileTracked;
                      const isSelected = selectedKeywords.has(item.keyword.toLowerCase());

                      return (
                        <tr
                          key={item.keyword}
                          className={`hover:bg-slate-800/30 transition ${
                            isSelected && !isFullyTracked ? 'bg-sky-500/5' : ''
                          }`}
                        >
                          {/* Checkbox */}
                          <td className="py-3 px-3">
                            {isFullyTracked ? (
                              <span
                                className="inline-flex h-4 w-4 rounded border border-slate-800 bg-slate-900/50 text-slate-600 items-center justify-center cursor-not-allowed"
                                title="Already tracked on desktop & mobile in this market"
                              >
                                <Check className="h-2.5 w-2.5 text-slate-600" />
                              </span>
                            ) : (
                              <input
                                type="checkbox"
                                checked={isSelected}
                                onChange={() => toggleSelectOne(item.keyword)}
                                className="rounded border-slate-700 bg-slate-900 text-sky-500 focus:ring-sky-500 cursor-pointer"
                              />
                            )}
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

                          {/* Opportunity Score */}
                          <td className="py-3 px-3 text-center">
                            <OpportunityBadge score={item.opportunity_score} />
                          </td>

                          {/* Gap Type */}
                          <td className="py-3 px-3 text-center">
                            <GapTypePill type={item.gap_type} />
                          </td>

                          {/* Search Volume */}
                          <td className="py-3 px-3 text-right font-mono font-semibold text-slate-100">
                            {formatNumber(item.search_volume)}
                          </td>

                          {/* Your Rank */}
                          <td className="py-3 px-3 text-center">
                            {item.project_rank ? (
                              <span className="inline-flex items-center px-2 py-0.5 rounded-full bg-slate-800 text-white font-mono font-bold text-[11px] border border-slate-700">
                                #{item.project_rank}
                              </span>
                            ) : (
                              <span className="text-slate-500 font-mono text-xs">-</span>
                            )}
                          </td>

                          {/* Competitor Ranks */}
                          <td className="py-3 px-3">
                            <div className="flex flex-wrap items-center gap-1.5">
                              {searchedCompetitors.map((comp) => {
                                const rankInfo = item.competitor_ranks[comp];
                                const rank = rankInfo?.rank;
                                const url = rankInfo?.url;

                                if (rank === null || rank === undefined) {
                                  return (
                                    <span
                                      key={comp}
                                      className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-900 text-slate-500 border border-slate-800"
                                      title={`${comp}: Not ranking in top 100`}
                                    >
                                      {comp.replace(/\..*$/, '')}: -
                                    </span>
                                  );
                                }

                                const isTopRank = rank <= 3;

                                return (
                                  <a
                                    key={comp}
                                    href={url || '#'}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={`inline-flex items-center gap-1 text-[10px] font-mono px-1.5 py-0.5 rounded border transition ${
                                      isTopRank
                                        ? 'bg-rose-500/15 text-rose-300 border-rose-500/30 hover:bg-rose-500/25'
                                        : 'bg-slate-800 text-slate-300 border-slate-700 hover:bg-slate-750'
                                    }`}
                                    title={`${comp}: #${rank} (${url || 'No URL'})`}
                                  >
                                    <span>
                                      {comp.replace(/\..*$/, '')}: #{rank}
                                    </span>
                                    {url && <ExternalLink className="h-2.5 w-2.5 opacity-60" />}
                                  </a>
                                );
                              })}
                            </div>
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

                          {/* Actions */}
                          <td className="py-3 px-3 text-right">
                            {isFullyTracked ? (
                              <span
                                className="inline-flex items-center gap-1 text-[11px] text-emerald-400 font-medium px-2 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/20"
                                title="Tracked on both Desktop and Mobile in this market"
                              >
                                <CheckCircle2 className="h-3 w-3" /> Tracked
                              </span>
                            ) : isDesktopTracked ? (
                              <div className="inline-flex items-center justify-end gap-1.5">
                                <span
                                  className="text-[10px] text-emerald-400 font-mono px-1.5 py-0.5 rounded bg-emerald-500/10 border border-emerald-500/20"
                                  title="Currently tracked on Desktop"
                                >
                                  Desktop
                                </span>
                                <button
                                  type="button"
                                  onClick={() => openTrackSingle(item, 'mobile')}
                                  className="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-slate-800 hover:bg-emerald-500/20 hover:text-emerald-400 hover:border-emerald-500/30 border border-slate-700 text-slate-300 text-[11px] font-medium transition cursor-pointer"
                                  title="Add Mobile tracking for this keyword"
                                >
                                  <Plus className="h-3 w-3" />
                                  <span>+ Mobile</span>
                                </button>
                              </div>
                            ) : isMobileTracked ? (
                              <div className="inline-flex items-center justify-end gap-1.5">
                                <span
                                  className="text-[10px] text-emerald-400 font-mono px-1.5 py-0.5 rounded bg-emerald-500/10 border border-emerald-500/20"
                                  title="Currently tracked on Mobile"
                                >
                                  Mobile
                                </span>
                                <button
                                  type="button"
                                  onClick={() => openTrackSingle(item, 'desktop')}
                                  className="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-slate-800 hover:bg-emerald-500/20 hover:text-emerald-400 hover:border-emerald-500/30 border border-slate-700 text-slate-300 text-[11px] font-medium transition cursor-pointer"
                                  title="Add Desktop tracking for this keyword"
                                >
                                  <Plus className="h-3 w-3" />
                                  <span>+ Desktop</span>
                                </button>
                              </div>
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
                <span className="font-medium text-slate-300">{results.length}</span> keyword gaps
                {selectedUntrackedCount > 0 && (
                  <span className="ml-2 text-sky-400">
                    ({selectedUntrackedCount} selected)
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
          !loading && (
            <div className="rounded-2xl border border-dashed border-slate-800 p-12 text-center space-y-4">
              <div className="mx-auto w-12 h-12 rounded-2xl bg-sky-500/10 border border-sky-500/20 flex items-center justify-center text-sky-400">
                <Users className="h-6 w-6" />
              </div>
              <div>
                <h3 className="text-sm font-semibold text-white">
                  Discover Competitor Keyword Opportunities
                </h3>
                <p className="mt-1 text-xs text-slate-400 max-w-md mx-auto">
                  Select 1 to 3 competitors above and click "Run Analysis" to surface missing keywords,
                  ranking gaps, and high-value search intersections cached for 14 days.
                </p>
              </div>
            </div>
          )
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
                    Keyword Gaps to Track ({keywordsToTrack.length})
                  </label>
                  <div className="max-h-28 overflow-y-auto rounded-lg bg-slate-950 border border-slate-800 p-2.5 font-mono text-[11px] text-slate-300 space-y-1">
                    {keywordsToTrack.map((k) => (
                      <div key={k.keyword} className="flex items-center justify-between">
                        <span>{k.keyword}</span>
                        <span className="text-slate-500">
                          Vol: {formatNumber(k.search_volume)} • Opp: {k.opportunity_score}
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
                    {keywordsToTrack.length === 1 && (
                      <div className="text-[10px] text-slate-400 mt-1">
                        {trackedSet.has(
                          makeTrackedKey(
                            keywordsToTrack[0].keyword,
                            trackDevice,
                            keywordsToTrack[0].location_code,
                            keywordsToTrack[0].language_code
                          )
                        ) ? (
                          <span className="text-amber-400">
                            Already tracked on {trackDevice} (will backfill metrics)
                          </span>
                        ) : (
                          <span className="text-emerald-400">
                            Will add new {trackDevice} monitor
                          </span>
                        )}
                      </div>
                    )}
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
