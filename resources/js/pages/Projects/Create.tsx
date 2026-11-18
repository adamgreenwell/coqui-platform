import { Head, Link, useForm } from '@inertiajs/react';
import {
  ArrowLeft,
  Building2,
  FolderKanban,
  Globe,
  Plus,
  Trash2,
} from 'lucide-react';
import { type FormEvent, useState } from 'react';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface ProjectCreateProps {
  workspace: WorkspaceSummary;
}

const COMMON_LOCATIONS = [
  { code: 2840, label: 'United States (US)' },
  { code: 2826, label: 'United Kingdom (UK)' },
  { code: 2124, label: 'Canada (CA)' },
  { code: 2036, label: 'Australia (AU)' },
  { code: 2276, label: 'Germany (DE)' },
  { code: 2250, label: 'France (FR)' },
];

export default function ProjectCreate({ workspace }: ProjectCreateProps) {
  const [competitorInput, setCompetitorInput] = useState('');

  const { data, setData, post, processing, errors } = useForm({
    name: '',
    url: '',
    location_code: 2840,
    language_code: 'en',
    crawl_cadence: 'weekly',
    competitors: [] as string[],
  });

  const handleAddCompetitor = () => {
    const trimmed = competitorInput.trim();
    if (!trimmed) return;
    if (data.competitors.length >= 5) return;
    if (data.competitors.includes(trimmed)) return;

    setData('competitors', [...data.competitors, trimmed]);
    setCompetitorInput('');
  };

  const handleRemoveCompetitor = (domainToRemove: string) => {
    setData(
      'competitors',
      data.competitors.filter((d) => d !== domainToRemove)
    );
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    post(`/workspaces/${workspace.id}/projects`);
  };

  return (
    <AppLayout currentWorkspace={workspace}>
      <Head title="Create Project - Coqui Platform" />

      <div className="max-w-2xl mx-auto space-y-6">
        <Link
          href={`/workspaces/${workspace.id}`}
          className="inline-flex items-center gap-1.5 text-xs text-slate-400 hover:text-white transition"
        >
          <ArrowLeft className="h-3.5 w-3.5" />
          <span>Back to workspace</span>
        </Link>

        <div className="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 sm:p-8">
          <div className="flex items-center gap-3 mb-6">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400">
              <FolderKanban className="h-5 w-5" />
            </div>
            <div>
              <h1 className="text-lg font-bold text-white">Create New SEO Project</h1>
              <p className="text-xs text-slate-400">
                Register a canonical domain to track SEO audits, keywords, backlinks, and competitors.
              </p>
            </div>
          </div>

          <form onSubmit={handleSubmit} className="space-y-5">
            {/* Project Name */}
            <div>
              <label htmlFor="name" className="block text-xs font-medium text-slate-300">
                Project Name
              </label>
              <input
                id="name"
                type="text"
                required
                value={data.name}
                onChange={(e) => setData('name', e.target.value)}
                className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                placeholder="e.g. Coqui SEO Production"
              />
              {errors.name && <p className="mt-1 text-xs text-red-400">{errors.name}</p>}
            </div>

            {/* Target URL */}
            <div>
              <label htmlFor="url" className="block text-xs font-medium text-slate-300">
                Website Domain or Root URL
              </label>
              <div className="mt-1 relative rounded-md shadow-sm">
                <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-500">
                  <Globe className="h-4 w-4" />
                </div>
                <input
                  id="url"
                  type="text"
                  required
                  value={data.url}
                  onChange={(e) => setData('url', e.target.value)}
                  className="block w-full pl-10 pr-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                  placeholder="https://example.com"
                />
              </div>
              {errors.url && <p className="mt-1 text-xs text-red-400">{errors.url}</p>}
              <p className="mt-1 text-[11px] text-slate-500">
                Protocols (http/https), www subdomains, and trailing paths will be automatically normalized to a canonical root host.
              </p>
            </div>

            {/* Locale & Search Engine Settings */}
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
              <div>
                <label htmlFor="location" className="block text-xs font-medium text-slate-300">
                  Target Search Market
                </label>
                <select
                  id="location"
                  value={data.location_code}
                  onChange={(e) => setData('location_code', parseInt(e.target.value, 10))}
                  className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                >
                  {COMMON_LOCATIONS.map((loc) => (
                    <option key={loc.code} value={loc.code}>
                      {loc.label}
                    </option>
                  ))}
                </select>
                {errors.location_code && (
                  <p className="mt-1 text-xs text-red-400">{errors.location_code}</p>
                )}
              </div>

              <div>
                <label htmlFor="language" className="block text-xs font-medium text-slate-300">
                  SERP Language
                </label>
                <select
                  id="language"
                  value={data.language_code}
                  onChange={(e) => setData('language_code', e.target.value)}
                  className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                >
                  <option value="en">English (en)</option>
                  <option value="es">Spanish (es)</option>
                  <option value="de">German (de)</option>
                  <option value="fr">French (fr)</option>
                </select>
                {errors.language_code && (
                  <p className="mt-1 text-xs text-red-400">{errors.language_code}</p>
                )}
              </div>

              <div>
                <label htmlFor="cadence" className="block text-xs font-medium text-slate-300">
                  Crawl Cadence
                </label>
                <select
                  id="cadence"
                  value={data.crawl_cadence}
                  onChange={(e) => setData('crawl_cadence', e.target.value)}
                  className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                >
                  <option value="weekly">Weekly Audit</option>
                  <option value="daily">Daily Audit</option>
                  <option value="monthly">Monthly Audit</option>
                  <option value="manual">Manual / Desktop Only</option>
                </select>
                {errors.crawl_cadence && (
                  <p className="mt-1 text-xs text-red-400">{errors.crawl_cadence}</p>
                )}
              </div>
            </div>

            {/* Tracked Competitors (Up to 5) */}
            <div className="pt-2 border-t border-slate-800">
              <label className="block text-xs font-medium text-slate-300">
                Tracked Competitor Domains <span className="text-slate-500">({data.competitors.length} / 5)</span>
              </label>
              <div className="mt-1.5 flex gap-2">
                <input
                  type="text"
                  disabled={data.competitors.length >= 5}
                  value={competitorInput}
                  onChange={(e) => setCompetitorInput(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault();
                      handleAddCompetitor();
                    }
                  }}
                  className="block w-full px-3 py-1.5 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 disabled:opacity-50"
                  placeholder="e.g. competitor.com"
                />
                <button
                  type="button"
                  disabled={!competitorInput.trim() || data.competitors.length >= 5}
                  onClick={handleAddCompetitor}
                  className="px-3 py-1.5 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-lg text-xs font-semibold text-slate-200 transition disabled:opacity-40 cursor-pointer shrink-0"
                >
                  Add
                </button>
              </div>

              {data.competitors.length > 0 && (
                <div className="mt-2.5 flex flex-wrap gap-1.5">
                  {data.competitors.map((domain) => (
                    <span
                      key={domain}
                      className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-slate-800 border border-slate-700 text-xs text-slate-200"
                    >
                      <span className="font-mono">{domain}</span>
                      <button
                        type="button"
                        onClick={() => handleRemoveCompetitor(domain)}
                        className="text-slate-400 hover:text-red-400 transition"
                      >
                        <Trash2 className="h-3 w-3" />
                      </button>
                    </span>
                  ))}
                </div>
              )}
            </div>

            {/* Form Actions */}
            <div className="pt-4 flex items-center justify-end gap-3 border-t border-slate-800">
              <Link
                href={`/workspaces/${workspace.id}`}
                className="px-4 py-2 rounded-lg text-xs font-medium text-slate-400 hover:text-white hover:bg-slate-800 transition"
              >
                Cancel
              </Link>
              <button
                type="submit"
                disabled={processing}
                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition disabled:opacity-50 shadow-lg shadow-emerald-500/10 cursor-pointer"
              >
                <Plus className="h-4 w-4" />
                <span>{processing ? 'Creating...' : 'Create Project'}</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </AppLayout>
  );
}
