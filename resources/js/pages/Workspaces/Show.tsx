import { Head, Link, useForm } from '@inertiajs/react';
import {
  Coins,
  Edit2,
  FolderKanban,
  Globe,
  Key,
  Laptop,
  Plus,
  Search,
  Shield,
  Users,
} from 'lucide-react';
import { type FormEvent, useState } from 'react';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface ProjectSummary {
  id: number;
  name: string;
  domain: string;
  canonical_url: string;
  last_crawled_at?: string | null;
}

interface WorkspaceDetail {
  id: number;
  name: string;
  slug: string;
  planTier: string;
  monthlyDfsBudgetUsd: number;
  trackedKeywordLimit: number;
  cloudCrawlUrlLimit: number;
  projectsCount: number;
  membersCount: number;
  isOwner: boolean;
  userRole: string;
}

interface WorkspaceShowProps {
  workspace: WorkspaceDetail;
  projects: ProjectSummary[];
  allWorkspaces: WorkspaceSummary[];
}

export default function WorkspaceShow({
  workspace,
  projects,
  allWorkspaces,
}: WorkspaceShowProps) {
  const [editingName, setEditingName] = useState(false);
  const { data, setData, put, processing, errors } = useForm({
    name: workspace.name,
  });

  const handleUpdateName = (e: FormEvent) => {
    e.preventDefault();
    put(`/workspaces/${workspace.id}`, {
      onSuccess: () => setEditingName(false),
    });
  };

  const canManage = workspace.userRole === 'owner' || workspace.userRole === 'admin';

  return (
    <AppLayout currentWorkspace={workspace} allWorkspaces={allWorkspaces}>
      <Head title={`${workspace.name} - Overview`} />

      <div className="space-y-8">
        {/* Workspace Header */}
        <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4 border-b border-slate-800 pb-6">
          <div>
            <div className="flex items-center gap-3">
              {editingName ? (
                <form onSubmit={handleUpdateName} className="flex items-center gap-2">
                  <input
                    type="text"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    className="px-2.5 py-1 bg-slate-900 border border-emerald-500 rounded text-xl font-bold text-white focus:outline-none"
                    autoFocus
                  />
                  <button
                    type="submit"
                    disabled={processing}
                    className="px-2 py-1 bg-emerald-500 text-slate-950 font-bold text-xs rounded hover:bg-emerald-400"
                  >
                    Save
                  </button>
                  <button
                    type="button"
                    onClick={() => setEditingName(false)}
                    className="px-2 py-1 text-slate-400 hover:text-white text-xs"
                  >
                    Cancel
                  </button>
                </form>
              ) : (
                <div className="flex items-center gap-2">
                  <h1 className="text-2xl font-bold tracking-tight text-white">
                    {workspace.name}
                  </h1>
                  {canManage && (
                    <button
                      type="button"
                      onClick={() => setEditingName(true)}
                      className="text-slate-500 hover:text-slate-300 transition p-1"
                      title="Edit name"
                    >
                      <Edit2 className="h-3.5 w-3.5" />
                    </button>
                  )}
                </div>
              )}

              <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 border border-emerald-500/20 px-2.5 py-0.5 text-xs font-medium text-emerald-400">
                <Shield className="h-3 w-3" />
                {workspace.planTier.toUpperCase()}
              </span>
              <span className="rounded bg-slate-800 border border-slate-700 px-2 py-0.5 text-[11px] font-mono text-slate-300">
                Role: {workspace.userRole}
              </span>
            </div>
            {errors.name && <p className="mt-1 text-xs text-red-400">{errors.name}</p>}
            <p className="mt-1 text-xs text-slate-400 font-mono">/{workspace.slug}</p>
          </div>

          <div className="flex items-center gap-2.5">
            <Link
              href={`/workspaces/${workspace.id}/tokens`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition"
            >
              <Key className="h-3.5 w-3.5 text-emerald-400" />
              <span>Desktop Tokens</span>
            </Link>
            <Link
              href={`/workspaces/${workspace.id}/members`}
              className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition"
            >
              <Users className="h-3.5 w-3.5 text-sky-400" />
              <span>Members ({workspace.membersCount})</span>
            </Link>
          </div>
        </div>

        {/* Metrics Grid */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <div className="flex items-center gap-2 text-slate-400 text-xs">
              <Coins className="h-4 w-4 text-emerald-400" />
              <span>Monthly Budget</span>
            </div>
            <div className="mt-2 text-xl font-bold text-white font-mono">
              ${workspace.monthlyDfsBudgetUsd.toFixed(2)}
            </div>
            <p className="mt-1 text-[11px] text-slate-500">DataForSEO & SE Ranking cap</p>
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <div className="flex items-center gap-2 text-slate-400 text-xs">
              <Globe className="h-4 w-4 text-sky-400" />
              <span>Crawl Limit</span>
            </div>
            <div className="mt-2 text-xl font-bold text-white font-mono">
              {workspace.cloudCrawlUrlLimit.toLocaleString()} URLs
            </div>
            <p className="mt-1 text-[11px] text-slate-500">Per cloud crawl audit</p>
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <div className="flex items-center gap-2 text-slate-400 text-xs">
              <Search className="h-4 w-4 text-amber-400" />
              <span>Tracked Keywords</span>
            </div>
            <div className="mt-2 text-xl font-bold text-white font-mono">
              {workspace.trackedKeywordLimit}
            </div>
            <p className="mt-1 text-[11px] text-slate-500">Active keyword SERP monitor</p>
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/40 p-4">
            <div className="flex items-center gap-2 text-slate-400 text-xs">
              <FolderKanban className="h-4 w-4 text-purple-400" />
              <span>Projects</span>
            </div>
            <div className="mt-2 text-xl font-bold text-white font-mono">
              {workspace.projectsCount}
            </div>
            <p className="mt-1 text-[11px] text-slate-500">Domains and subdomains</p>
          </div>
        </div>

        {/* Projects Section */}
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-base font-semibold text-white">Active Projects</h2>
            <Link
              href={`/workspaces/${workspace.id}/projects/create`}
              className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition shadow-lg shadow-emerald-500/10 cursor-pointer"
            >
              <Plus className="h-3.5 w-3.5" />
              <span>New Project</span>
            </Link>
          </div>

          {projects.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-slate-800 bg-slate-900/20 p-8 text-center space-y-3">
              <FolderKanban className="mx-auto h-8 w-8 text-slate-600" />
              <div>
                <h3 className="text-sm font-semibold text-white">No projects yet</h3>
                <p className="mt-1 text-xs text-slate-400 max-w-sm mx-auto">
                  Add a domain or connect Coqui Desktop using a personal access token to automatically sync crawls into this workspace.
                </p>
              </div>
              <div>
                <Link
                  href={`/workspaces/${workspace.id}/projects/create`}
                  className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition cursor-pointer"
                >
                  <Plus className="h-3.5 w-3.5" />
                  <span>Create First Project</span>
                </Link>
              </div>
            </div>
          ) : (
            <div className="rounded-xl border border-slate-800 bg-slate-900/60 overflow-hidden divide-y divide-slate-800">
              {projects.map((p) => (
                <Link
                  key={p.id}
                  href={`/workspaces/${workspace.id}/projects/${p.id}`}
                  className="p-4 flex items-center justify-between hover:bg-slate-800/50 transition group block"
                >
                  <div>
                    <h4 className="text-sm font-semibold text-white group-hover:text-emerald-300 transition">
                      {p.name}
                    </h4>
                    <p className="text-xs text-slate-400 font-mono">{p.domain}</p>
                  </div>
                  <div className="text-right text-xs text-slate-500">
                    {p.last_crawled_at ? `Crawled ${new Date(p.last_crawled_at).toLocaleDateString()}` : 'Never crawled'}
                  </div>
                </Link>
              ))}
            </div>
          )}
        </div>

        {/* Coqui Desktop Sync Quickstart Banner */}
        <div className="rounded-2xl border border-emerald-500/20 bg-emerald-950/10 p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
          <div className="flex items-start gap-4">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400">
              <Laptop className="h-5 w-5" />
            </div>
            <div>
              <h3 className="text-sm font-semibold text-white">Coqui Desktop Synchronization</h3>
              <p className="mt-1 text-xs text-slate-400 max-w-xl">
                Run high-speed site audits locally on Rust with Coqui Desktop (Tauri) and stream full crawl snapshots directly into this cloud workspace.
              </p>
            </div>
          </div>
          <Link
            href={`/workspaces/${workspace.id}/tokens`}
            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 text-xs font-semibold transition shrink-0 self-start md:self-auto cursor-pointer"
          >
            <Key className="h-4 w-4" />
            <span>Generate Desktop Token</span>
          </Link>
        </div>
      </div>
    </AppLayout>
  );
}
