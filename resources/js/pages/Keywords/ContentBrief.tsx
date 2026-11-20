import { Head, Link, router } from '@inertiajs/react';
import {
  ArrowLeft,
  BookOpen,
  Check,
  ChevronDown,
  ChevronRight,
  Copy,
  Download,
  ExternalLink,
  FileCode,
  FileText,
  HelpCircle,
  Layers,
  ListTree,
  Radio,
  RefreshCw,
  Search,
  Sparkles,
} from 'lucide-react';
import { type FormEvent, useState } from 'react';
import AppLayout from '../../layouts/AppLayout';

interface CompetitorItem {
  rank: number;
  url: string;
  domain: string;
  title: string;
  description?: string | null;
  wordCount: number;
  headings: {
    h1: string[];
    h2: string[];
    h3: string[];
  };
  schemaTypes: string[];
  internalLinks: number;
  externalLinks: number;
  status?: number | null;
  error?: string | null;
}

interface BriefData {
  keyword: string;
  searchVolume: number;
  cpc: number;
  metrics: {
    avgWordCountTop10: number;
    avgWordCountTop3: number;
    minWords: number;
    maxWords: number;
    successfulCompetitorsScraped: number;
    totalCompetitorsTargeted: number;
  };
  recommendations: {
    suggestedWordCount: string;
    recommendedSchemas: string[];
    keyH2Themes: string[];
    questionsToAnswer: string[];
  };
  competitors: CompetitorItem[];
}

interface ContentBriefProp {
  id: number;
  keyword: string;
  searchVolume: number;
  cpc: number;
  recommendedWordsMin: number;
  recommendedWordsMax: number;
  data: BriefData;
  markdown: string;
  createdAt?: string;
}

interface RecentBriefSummary {
  id: number;
  keyword: string;
  searchVolume: number;
  wordRange: string;
  createdAt?: string;
}

interface Props {
  workspace: {
    id: string;
    name: string;
    slug: string;
  };
  project: {
    id: string;
    name: string;
    domain: string;
    canonicalUrl: string;
    locationCode: number;
    languageCode: string;
  };
  selectedKeyword?: string;
  brief?: ContentBriefProp | null;
  recentBriefs: RecentBriefSummary[];
}

export default function ContentBriefPage({
  workspace,
  project,
  selectedKeyword = '',
  brief,
  recentBriefs,
}: Props) {
  const [keywordInput, setKeywordInput] = useState(selectedKeyword);
  const [isGenerating, setIsGenerating] = useState(false);
  const [activeTab, setActiveTab] = useState<'matrix' | 'outline' | 'markdown'>('matrix');
  const [expandedRows, setExpandedRows] = useState<Record<number, boolean>>({});
  const [copied, setCopied] = useState(false);

  const toggleRow = (rank: number) => {
    setExpandedRows((prev) => ({ ...prev, [rank]: !prev[rank] }));
  };

  const handleGenerate = (e: FormEvent) => {
    e.preventDefault();
    const query = keywordInput.trim();
    if (!query) return;

    setIsGenerating(true);
    router.post(
      `/workspaces/${workspace.id}/projects/${project.id}/keywords/brief`,
      { keyword: query },
      {
        onFinish: () => setIsGenerating(false),
      }
    );
  };

  const handleCopyMarkdown = () => {
    if (!brief) return;
    navigator.clipboard.writeText(brief.markdown);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <AppLayout>
      <Head title={brief ? `Brief: ${brief.keyword} — ${project.name}` : `Content Briefs — ${project.name}`} />

      <div className="space-y-6">
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
          <Link
            href={`/workspaces/${workspace.id}/projects/${project.id}`}
            className="hover:text-white transition"
          >
            {project.name}
          </Link>
          <span>/</span>
          <span className="text-white font-medium">Content Briefs</span>
        </div>

        {/* Top Header & Search Generator Bar */}
        <div className="rounded-2xl border border-slate-800 bg-slate-900/60 backdrop-blur p-6 space-y-4">
          <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
              <div className="flex items-center gap-2">
                <Sparkles className="h-5 w-5 text-sky-400" />
                <h1 className="text-xl font-bold text-white tracking-tight">SERP Top-10 Content Brief</h1>
              </div>
              <p className="mt-1 text-xs text-slate-400">
                Scrapes and analyzes headings, word counts, and schema markup across Google top-10 competitors.
              </p>
            </div>

            {brief && (
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={handleCopyMarkdown}
                  className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-xs font-medium text-slate-200 transition cursor-pointer"
                >
                  {copied ? <Check className="h-3.5 w-3.5 text-emerald-400" /> : <Copy className="h-3.5 w-3.5 text-slate-400" />}
                  <span>{copied ? 'Copied Brief!' : 'Copy Markdown'}</span>
                </button>

                <a
                  href={`/workspaces/${workspace.id}/projects/${project.id}/keywords/brief/download?keyword=${encodeURIComponent(brief.keyword)}`}
                  className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-sky-500 hover:bg-sky-400 text-slate-950 font-semibold text-xs transition cursor-pointer"
                >
                  <Download className="h-3.5 w-3.5" />
                  <span>Download .md</span>
                </a>
              </div>
            )}
          </div>

          {/* Search Input Form */}
          <form onSubmit={handleGenerate} className="flex gap-2">
            <div className="relative flex-1">
              <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-500" />
              <input
                type="text"
                value={keywordInput}
                onChange={(e) => setKeywordInput(e.target.value)}
                placeholder="Enter target keyword (e.g. 'technical seo audit', 'b2b saas pricing')..."
                className="w-full pl-10 pr-4 py-2 bg-slate-950/80 border border-slate-700 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-sky-500/50"
              />
            </div>
            <button
              type="submit"
              disabled={isGenerating || !keywordInput.trim()}
              className="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-sky-500 hover:bg-sky-400 text-slate-950 font-semibold text-sm transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <RefreshCw className={`h-4 w-4 ${isGenerating ? 'animate-spin' : ''}`} />
              <span>{isGenerating ? 'Scraping SERP...' : 'Generate Brief'}</span>
            </button>
          </form>

          {/* Recent Briefs Pills */}
          {recentBriefs.length > 0 && (
            <div className="flex items-center gap-2 flex-wrap pt-1 text-xs">
              <span className="text-slate-500 font-medium">Recent briefs:</span>
              {recentBriefs.map((rb) => (
                <Link
                  key={rb.id}
                  href={`/workspaces/${workspace.id}/projects/${project.id}/keywords/brief?keyword=${encodeURIComponent(rb.keyword)}`}
                  className={`px-2.5 py-1 rounded-lg border transition font-mono text-[11px] ${
                    selectedKeyword.toLowerCase() === rb.keyword.toLowerCase()
                      ? 'bg-sky-500/20 text-sky-300 border-sky-500/40'
                      : 'bg-slate-800/60 hover:bg-slate-800 text-slate-300 border-slate-700/60'
                  }`}
                >
                  {rb.keyword}
                </Link>
              ))}
            </div>
          )}
        </div>

        {/* Content Brief View */}
        {brief ? (
          <div className="space-y-6">
            {/* Core Metrics Grid */}
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
                <div className="flex items-center justify-between text-slate-400 text-xs">
                  <span>Target Word Count</span>
                  <BookOpen className="h-4 w-4 text-sky-400" />
                </div>
                <div className="mt-2 text-xl font-bold text-white font-mono">
                  {brief.recommendedWordsMin.toLocaleString()} – {brief.recommendedWordsMax.toLocaleString()}
                </div>
                <p className="mt-1 text-[11px] text-slate-500">
                  Top-3 Competitor Avg: {brief.data.metrics.avgWordCountTop3.toLocaleString()} words
                </p>
              </div>

              <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
                <div className="flex items-center justify-between text-slate-400 text-xs">
                  <span>Monthly Search Volume</span>
                  <Search className="h-4 w-4 text-emerald-400" />
                </div>
                <div className="mt-2 text-xl font-bold text-white font-mono">
                  {brief.searchVolume.toLocaleString()}
                </div>
                <p className="mt-1 text-[11px] text-slate-500">
                  Target CPC: ${brief.cpc.toFixed(2)}
                </p>
              </div>

              <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
                <div className="flex items-center justify-between text-slate-400 text-xs">
                  <span>Recommended Schema</span>
                  <Layers className="h-4 w-4 text-purple-400" />
                </div>
                <div className="mt-2 flex flex-wrap gap-1">
                  {brief.data.recommendations.recommendedSchemas.slice(0, 2).map((s) => (
                    <span
                      key={s}
                      className="px-2 py-0.5 rounded text-[11px] font-mono font-medium bg-purple-500/15 text-purple-300 border border-purple-500/30"
                    >
                      {s}
                    </span>
                  ))}
                </div>
                <p className="mt-1 text-[11px] text-slate-500">
                  Structured data detected in top competitors
                </p>
              </div>

              <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
                <div className="flex items-center justify-between text-slate-400 text-xs">
                  <span>SERP Competitors Scraped</span>
                  <Radio className="h-4 w-4 text-amber-400" />
                </div>
                <div className="mt-2 text-xl font-bold text-white font-mono">
                  {brief.data.metrics.successfulCompetitorsScraped} / {brief.data.metrics.totalCompetitorsTargeted}
                </div>
                <p className="mt-1 text-[11px] text-slate-500">
                  Reachable organic ranking pages
                </p>
              </div>
            </div>

            {/* View Mode Tabs */}
            <div className="flex items-center gap-2 border-b border-slate-800 pb-3">
              <button
                type="button"
                onClick={() => setActiveTab('matrix')}
                className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition cursor-pointer ${
                  activeTab === 'matrix'
                    ? 'bg-sky-500/20 text-sky-300 border border-sky-500/40'
                    : 'text-slate-400 hover:text-white'
                }`}
              >
                <ListTree className="h-3.5 w-3.5" />
                <span>Competitor Structure Matrix ({brief.data.competitors.length})</span>
              </button>

              <button
                type="button"
                onClick={() => setActiveTab('outline')}
                className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition cursor-pointer ${
                  activeTab === 'outline'
                    ? 'bg-sky-500/20 text-sky-300 border border-sky-500/40'
                    : 'text-slate-400 hover:text-white'
                }`}
              >
                <HelpCircle className="h-3.5 w-3.5" />
                <span>Headings & FAQ Ideas ({brief.data.recommendations.keyH2Themes.length + brief.data.recommendations.questionsToAnswer.length})</span>
              </button>

              <button
                type="button"
                onClick={() => setActiveTab('markdown')}
                className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium transition cursor-pointer ${
                  activeTab === 'markdown'
                    ? 'bg-sky-500/20 text-sky-300 border border-sky-500/40'
                    : 'text-slate-400 hover:text-white'
                }`}
              >
                <FileCode className="h-3.5 w-3.5" />
                <span>Markdown Brief</span>
              </button>
            </div>

            {/* TAB 1: Competitor Structure Matrix */}
            {activeTab === 'matrix' && (
              <div className="rounded-xl border border-slate-800 bg-slate-900/40 overflow-hidden">
                <div className="overflow-x-auto">
                  <table className="w-full text-left text-xs border-collapse">
                    <thead>
                      <tr className="border-b border-slate-800 bg-slate-950/60 text-slate-400">
                        <th className="py-2.5 px-3 font-medium w-12 text-center">Rank</th>
                        <th className="py-2.5 px-4 font-medium">Domain & Page Title</th>
                        <th className="py-2.5 px-3 font-medium text-right">Words</th>
                        <th className="py-2.5 px-3 font-medium text-center">Status</th>
                        <th className="py-2.5 px-3 font-medium">Schema Markup</th>
                        <th className="py-2.5 px-3 font-medium text-right">Links (Int/Ext)</th>
                        <th className="py-2.5 px-3 font-medium w-8"></th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-800/60">
                      {brief.data.competitors.map((c) => {
                        const isExpanded = !!expandedRows[c.rank];
                        const hasOutlines = (c.headings.h1.length + c.headings.h2.length + c.headings.h3.length) > 0;

                        return (
                          <tbody key={c.rank} className="divide-y divide-slate-800/60">
                            <tr
                              onClick={() => hasOutlines && toggleRow(c.rank)}
                              className={`transition text-slate-300 ${
                                hasOutlines ? 'hover:bg-slate-800/30 cursor-pointer' : ''
                              }`}
                            >
                              <td className="py-3 px-3 text-center font-bold text-sky-400 font-mono">
                                #{c.rank}
                              </td>
                              <td className="py-3 px-4 max-w-md">
                                <div className="flex items-center gap-1.5 font-semibold text-slate-200">
                                  <span className="font-mono text-xs text-sky-300">{c.domain}</span>
                                  <a
                                    href={c.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    onClick={(e) => e.stopPropagation()}
                                    className="text-slate-500 hover:text-white transition"
                                  >
                                    <ExternalLink className="h-3 w-3" />
                                  </a>
                                </div>
                                <div className="text-[11px] text-slate-400 truncate mt-0.5">
                                  {c.title}
                                </div>
                              </td>
                              <td className="py-3 px-3 text-right font-mono font-medium">
                                {c.wordCount > 0 ? c.wordCount.toLocaleString() : '—'}
                              </td>
                              <td className="py-3 px-3 text-center">
                                {c.error ? (
                                  <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500/15 text-amber-400 border border-amber-500/30">
                                    {c.error}
                                  </span>
                                ) : (
                                  <span className="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                                    {c.status || 200}
                                  </span>
                                )}
                              </td>
                              <td className="py-3 px-3">
                                {c.schemaTypes.length > 0 ? (
                                  <div className="flex flex-wrap gap-1">
                                    {c.schemaTypes.slice(0, 2).map((st) => (
                                      <span
                                        key={st}
                                        className="px-1.5 py-0.5 rounded text-[10px] font-mono bg-slate-800 text-slate-300 border border-slate-700"
                                      >
                                        {st}
                                      </span>
                                    ))}
                                    {c.schemaTypes.length > 2 && (
                                      <span className="text-[10px] text-slate-500 self-center">
                                        +{c.schemaTypes.length - 2}
                                      </span>
                                    )}
                                  </div>
                                ) : (
                                  <span className="text-slate-600">—</span>
                                )}
                              </td>
                              <td className="py-3 px-3 text-right font-mono text-slate-400 text-[11px]">
                                {c.internalLinks} / {c.externalLinks}
                              </td>
                              <td className="py-3 px-3 text-center text-slate-500">
                                {hasOutlines && (
                                  isExpanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />
                                )}
                              </td>
                            </tr>

                            {/* Expanded Outline Row */}
                            {isExpanded && hasOutlines && (
                              <tr className="bg-slate-950/60">
                                <td colSpan={7} className="p-4 border-t border-slate-800/80">
                                  <div className="space-y-3 pl-6 border-l-2 border-sky-500/40">
                                    {c.headings.h1.length > 0 && (
                                      <div>
                                        <span className="text-[10px] uppercase font-bold text-sky-400 tracking-wider">H1 Headings</span>
                                        <div className="mt-1 space-y-1">
                                          {c.headings.h1.map((h1, i) => (
                                            <div key={i} className="text-xs font-semibold text-white">
                                              {h1}
                                            </div>
                                          ))}
                                        </div>
                                      </div>
                                    )}

                                    {c.headings.h2.length > 0 && (
                                      <div>
                                        <span className="text-[10px] uppercase font-bold text-emerald-400 tracking-wider">H2 Sections ({c.headings.h2.length})</span>
                                        <div className="mt-1 grid grid-cols-1 md:grid-cols-2 gap-1.5">
                                          {c.headings.h2.map((h2, i) => (
                                            <div key={i} className="text-xs text-slate-300 flex items-start gap-1.5">
                                              <span className="text-slate-500 font-mono">•</span>
                                              <span>{h2}</span>
                                            </div>
                                          ))}
                                        </div>
                                      </div>
                                    )}

                                    {c.headings.h3.length > 0 && (
                                      <div>
                                        <span className="text-[10px] uppercase font-bold text-purple-400 tracking-wider">H3 Subsections ({c.headings.h3.length})</span>
                                        <div className="mt-1 grid grid-cols-1 md:grid-cols-2 gap-1.5 text-xs text-slate-400">
                                          {c.headings.h3.slice(0, 10).map((h3, i) => (
                                            <div key={i} className="flex items-start gap-1.5">
                                              <span className="text-slate-600 font-mono">-</span>
                                              <span>{h3}</span>
                                            </div>
                                          ))}
                                        </div>
                                      </div>
                                    )}
                                  </div>
                                </td>
                              </tr>
                            )}
                          </tbody>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {/* TAB 2: Outline & FAQ Ideas */}
            {activeTab === 'outline' && (
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                {/* Core Themes */}
                <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-5 space-y-4">
                  <div className="flex items-center gap-2">
                    <ListTree className="h-4 w-4 text-sky-400" />
                    <h3 className="text-sm font-semibold text-white">Core Themes to Cover</h3>
                  </div>
                  <p className="text-xs text-slate-400">
                    Recurring H2 subtopics identified across top ranking competitor articles:
                  </p>
                  <div className="space-y-2">
                    {brief.data.recommendations.keyH2Themes.map((theme, idx) => (
                      <div
                        key={idx}
                        className="p-3 rounded-lg bg-slate-950/60 border border-slate-800 text-xs text-slate-200 flex items-start gap-2.5"
                      >
                        <span className="font-mono text-sky-400 font-bold">{idx + 1}.</span>
                        <span>{theme}</span>
                      </div>
                    ))}
                  </div>
                </div>

                {/* People Also Ask Questions */}
                <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-5 space-y-4">
                  <div className="flex items-center gap-2">
                    <HelpCircle className="h-4 w-4 text-emerald-400" />
                    <h3 className="text-sm font-semibold text-white">Questions to Answer (FAQ Section)</h3>
                  </div>
                  <p className="text-xs text-slate-400">
                    High-intent questions extracted directly from Google People Also Ask features:
                  </p>
                  <div className="space-y-2">
                    {brief.data.recommendations.questionsToAnswer.length > 0 ? (
                      brief.data.recommendations.questionsToAnswer.map((q, idx) => (
                        <div
                          key={idx}
                          className="p-3 rounded-lg bg-slate-950/60 border border-slate-800 text-xs text-slate-200 flex items-start gap-2.5"
                        >
                          <span className="text-emerald-400 font-bold font-mono">Q:</span>
                          <span className="font-medium">{q}</span>
                        </div>
                      ))
                    ) : (
                      <p className="text-xs text-slate-500 italic">
                        No PAA questions in SERP; refer to standard interrogatives in markdown brief.
                      </p>
                    )}
                  </div>
                </div>
              </div>
            )}

            {/* TAB 3: Markdown Preview */}
            {activeTab === 'markdown' && (
              <div className="rounded-xl border border-slate-800 bg-slate-950/80 p-6 space-y-4">
                <div className="flex items-center justify-between pb-3 border-b border-slate-800">
                  <span className="text-xs font-mono text-slate-400">Generated Editorial Markdown Brief</span>
                  <button
                    type="button"
                    onClick={handleCopyMarkdown}
                    className="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-slate-800 hover:bg-slate-700 text-xs text-slate-300 transition"
                  >
                    {copied ? <Check className="h-3.5 w-3.5 text-emerald-400" /> : <Copy className="h-3.5 w-3.5" />}
                    <span>{copied ? 'Copied!' : 'Copy Markdown'}</span>
                  </button>
                </div>
                <pre className="text-xs font-mono text-slate-300 whitespace-pre-wrap leading-relaxed max-h-[600px] overflow-y-auto">
                  {brief.markdown}
                </pre>
              </div>
            )}
          </div>
        ) : (
          <div className="rounded-2xl border border-dashed border-slate-800 p-12 text-center space-y-4">
            <div className="w-12 h-12 rounded-xl bg-sky-500/10 text-sky-400 flex items-center justify-center mx-auto">
              <FileText className="h-6 w-6" />
            </div>
            <div>
              <h2 className="text-base font-semibold text-white">No Content Brief Selected</h2>
              <p className="mt-1 text-xs text-slate-400 max-w-md mx-auto">
                Enter a target keyword above to scrape Google top-10 competitor pages, extract heading structures, and generate an actionable content brief.
              </p>
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
