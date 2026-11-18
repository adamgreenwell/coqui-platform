import { Head, Link, router, useForm } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowDown,
  ArrowLeft,
  ArrowUp,
  Globe,
  Plus,
  Save,
  Settings,
  Trash2,
} from 'lucide-react';
import { type FormEvent } from 'react';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface CompetitorItem {
  id: number;
  domain: string;
  label?: string | null;
  sort_order: number;
}

interface ProjectSettingsProps {
  workspace: WorkspaceSummary;
  project: {
    id: number;
    name: string;
    domain: string;
    canonical_url: string;
    location_code: number;
    language_code: string;
    crawl_cadence: string;
  };
  competitors: CompetitorItem[];
}

const COMMON_LOCATIONS = [
  { code: 2840, label: 'United States (US)' },
  { code: 2826, label: 'United Kingdom (UK)' },
  { code: 2124, label: 'Canada (CA)' },
  { code: 2036, label: 'Australia (AU)' },
  { code: 2276, label: 'Germany (DE)' },
  { code: 2250, label: 'France (FR)' },
];

export default function ProjectSettings({
  workspace,
  project,
  competitors,
}: ProjectSettingsProps) {
  const { data, setData, put, processing, errors } = useForm({
    name: project.name,
    location_code: project.location_code,
    language_code: project.language_code,
    crawl_cadence: project.crawl_cadence,
  });

  const competitorForm = useForm({
    domain: '',
    label: '',
  });

  const handleUpdateSettings = (e: FormEvent) => {
    e.preventDefault();
    put(`/workspaces/${workspace.id}/projects/${project.id}`);
  };

  const handleAddCompetitor = (e: FormEvent) => {
    e.preventDefault();
    competitorForm.post(
      `/workspaces/${workspace.id}/projects/${project.id}/competitors`,
      {
        preserveScroll: true,
        onSuccess: () => {
          competitorForm.reset();
        },
      }
    );
  };

  const handleMoveCompetitor = (index: number, direction: 'up' | 'down') => {
    const newItems = [...competitors];
    const targetIndex = direction === 'up' ? index - 1 : index + 1;
    if (targetIndex < 0 || targetIndex >= newItems.length) return;

    const temp = newItems[index];
    newItems[index] = newItems[targetIndex];
    newItems[targetIndex] = temp;

    router.post(
      `/workspaces/${workspace.id}/projects/${project.id}/competitors/reorder`,
      {
        order: newItems.map((c) => c.id),
      }
    );
  };

  const handleDeleteCompetitor = (competitor: CompetitorItem) => {
    if (confirm(`Remove competitor ${competitor.domain}?`)) {
      router.delete(
        `/workspaces/${workspace.id}/projects/${project.id}/competitors/${competitor.id}`
      );
    }
  };

  const handleDeleteProject = () => {
    if (
      confirm(
        `Are you sure you want to delete project "${project.name}"? Historical crawls and tracked keywords will be deleted.`
      )
    ) {
      router.delete(`/workspaces/${workspace.id}/projects/${project.id}`);
    }
  };

  return (
    <AppLayout currentWorkspace={workspace}>
      <Head title={`${project.name} - Project Settings`} />

      <div className="max-w-3xl mx-auto space-y-8">
        {/* Navigation Breadcrumb */}
        <div className="flex items-center gap-2 text-xs text-slate-400">
          <Link
            href={`/workspaces/${workspace.id}/projects/${project.id}`}
            className="hover:text-white transition flex items-center gap-1"
          >
            <ArrowLeft className="h-3 w-3" />
            <span>Back to project</span>
          </Link>
          <span>/</span>
          <span className="text-white font-medium">Settings</span>
        </div>

        {/* General Settings */}
        <div className="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 sm:p-8 space-y-6">
          <div className="border-b border-slate-800 pb-4">
            <h2 className="text-base font-bold text-white flex items-center gap-2">
              <Settings className="h-5 w-5 text-emerald-400" />
              <span>Project Configuration</span>
            </h2>
            <p className="text-xs text-slate-400">
              Manage canonical domain parameters, default target search engine location, and crawl cadence.
            </p>
          </div>

          <form onSubmit={handleUpdateSettings} className="space-y-4">
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
                className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
              />
              {errors.name && <p className="mt-1 text-xs text-red-400">{errors.name}</p>}
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label className="block text-xs font-medium text-slate-300">
                  Canonical Domain
                </label>
                <input
                  type="text"
                  disabled
                  value={project.domain}
                  className="mt-1 block w-full px-3 py-2 bg-slate-950/50 border border-slate-800 rounded-lg text-xs font-mono text-slate-400 cursor-not-allowed"
                />
              </div>

              <div>
                <label className="block text-xs font-medium text-slate-300">
                  Canonical Root URL
                </label>
                <input
                  type="text"
                  disabled
                  value={project.canonical_url}
                  className="mt-1 block w-full px-3 py-2 bg-slate-950/50 border border-slate-800 rounded-lg text-xs font-mono text-slate-400 cursor-not-allowed"
                />
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
              <div>
                <label htmlFor="location" className="block text-xs font-medium text-slate-300">
                  Target Market
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
                  Language Code
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

            <div className="pt-3 flex justify-end">
              <button
                type="submit"
                disabled={processing}
                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition disabled:opacity-50 cursor-pointer"
              >
                <Save className="h-4 w-4" />
                <span>{processing ? 'Saving...' : 'Save Changes'}</span>
              </button>
            </div>
          </form>
        </div>

        {/* Competitor Registry Management */}
        <div className="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 sm:p-8 space-y-6">
          <div className="border-b border-slate-800 pb-4 flex items-center justify-between">
            <div>
              <h2 className="text-base font-bold text-white">Tracked Competitor Domains</h2>
              <p className="text-xs text-slate-400">
                Track up to 5 competitors. Reorder priority or remove domains.
              </p>
            </div>
            <span className="text-xs font-mono text-slate-400">
              {competitors.length} / 5
            </span>
          </div>

          {/* Add Competitor Form */}
          {competitors.length < 5 && (
            <form onSubmit={handleAddCompetitor} className="space-y-2">
              <div className="flex flex-col sm:flex-row gap-2">
                <input
                  type="text"
                  required
                  value={competitorForm.data.domain}
                  onChange={(e) => competitorForm.setData('domain', e.target.value)}
                  placeholder="Competitor URL (e.g. competitor.com)"
                  className="flex-1 px-3 py-1.5 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                />
                <input
                  type="text"
                  value={competitorForm.data.label}
                  onChange={(e) => competitorForm.setData('label', e.target.value)}
                  placeholder="Label (optional)"
                  className="w-full sm:w-44 px-3 py-1.5 bg-slate-950 border border-slate-700 rounded-lg text-xs text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                />
                <button
                  type="submit"
                  disabled={competitorForm.processing}
                  className="inline-flex items-center justify-center gap-1 px-3 py-1.5 bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs rounded-lg transition shrink-0 cursor-pointer disabled:opacity-50"
                >
                  <Plus className="h-3.5 w-3.5" />
                  <span>{competitorForm.processing ? 'Adding...' : 'Add'}</span>
                </button>
              </div>
              {competitorForm.errors.domain && (
                <p className="text-xs text-red-400">{competitorForm.errors.domain}</p>
              )}
              {competitorForm.errors.label && (
                <p className="text-xs text-red-400">{competitorForm.errors.label}</p>
              )}
            </form>
          )}

          {/* Competitors List with Reordering */}
          <div className="divide-y divide-slate-800 border border-slate-800 rounded-xl overflow-hidden bg-slate-950/40">
            {competitors.length === 0 ? (
              <div className="p-4 text-center text-xs text-slate-500">
                No competitor domains added yet.
              </div>
            ) : (
              competitors.map((comp, index) => (
                <div
                  key={comp.id}
                  className="p-3.5 flex items-center justify-between hover:bg-slate-800/30 transition"
                >
                  <div className="flex items-center gap-3">
                    <div className="flex flex-col gap-1">
                      <button
                        type="button"
                        disabled={index === 0}
                        onClick={() => handleMoveCompetitor(index, 'up')}
                        className="text-slate-500 hover:text-white disabled:opacity-20 cursor-pointer"
                        title="Move up"
                      >
                        <ArrowUp className="h-3 w-3" />
                      </button>
                      <button
                        type="button"
                        disabled={index === competitors.length - 1}
                        onClick={() => handleMoveCompetitor(index, 'down')}
                        className="text-slate-500 hover:text-white disabled:opacity-20 cursor-pointer"
                        title="Move down"
                      >
                        <ArrowDown className="h-3 w-3" />
                      </button>
                    </div>
                    <div>
                      <div className="text-xs font-semibold text-white font-mono">
                        {comp.domain}
                      </div>
                      {comp.label && (
                        <div className="text-[11px] text-slate-400">{comp.label}</div>
                      )}
                    </div>
                  </div>

                  <button
                    type="button"
                    onClick={() => handleDeleteCompetitor(comp)}
                    className="p-1.5 text-slate-400 hover:text-red-400 rounded transition cursor-pointer"
                    title="Remove competitor"
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </div>
              ))
            )}
          </div>
        </div>

        {/* Danger Zone */}
        <div className="rounded-2xl border border-red-500/20 bg-red-950/10 p-6 sm:p-8 space-y-4">
          <div className="flex items-center gap-2 text-red-400 text-sm font-bold">
            <AlertTriangle className="h-5 w-5" />
            <span>Danger Zone</span>
          </div>
          <p className="text-xs text-slate-400">
            Deleting this project will remove all associated crawl runs, URLs, tracked keyword configurations, and backlink monitors.
          </p>
          <div className="pt-2">
            <button
              type="button"
              onClick={handleDeleteProject}
              className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-red-500/20 border border-red-500/30 text-red-300 hover:bg-red-500/30 font-semibold text-xs transition cursor-pointer"
            >
              <Trash2 className="h-4 w-4" />
              <span>Delete Project</span>
            </button>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
