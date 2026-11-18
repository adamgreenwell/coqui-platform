import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Building2, Plus, Shield } from 'lucide-react';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface WorkspacesIndexProps {
  workspaces: WorkspaceSummary[];
}

export default function WorkspacesIndex({ workspaces }: WorkspacesIndexProps) {
  return (
    <AppLayout allWorkspaces={workspaces}>
      <Head title="Workspaces - Coqui Platform" />

      <div className="space-y-6">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-white">Your Workspaces</h1>
            <p className="mt-1 text-xs text-slate-400">
              Select a workspace to manage projects, SEO crawls, team access, and desktop sync tokens.
            </p>
          </div>
          <Link
            href="/workspaces/create"
            className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition shadow-lg shadow-emerald-500/10 self-start sm:self-auto"
          >
            <Plus className="h-4 w-4" />
            <span>New Workspace</span>
          </Link>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {workspaces.map((ws) => (
            <Link
              key={ws.id}
              href={`/workspaces/${ws.id}`}
              className="group block rounded-xl border border-slate-800 bg-slate-900/60 p-5 hover:border-emerald-500/40 hover:bg-slate-900 transition shadow-sm"
            >
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400">
                    <Building2 className="h-5 w-5" />
                  </div>
                  <div>
                    <h3 className="text-sm font-semibold text-white group-hover:text-emerald-300 transition">
                      {ws.name}
                    </h3>
                    <p className="text-xs font-mono text-slate-500">/{ws.slug}</p>
                  </div>
                </div>
                <span className="inline-flex items-center gap-1 rounded-full bg-slate-800 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-300 border border-slate-700">
                  <Shield className="h-2.5 w-2.5 text-emerald-400" />
                  {ws.plan_tier || ws.planTier || 'free'}
                </span>
              </div>

              <div className="mt-6 flex items-center justify-between text-xs text-slate-400 border-t border-slate-800/80 pt-3">
                <span>View workspace</span>
                <ArrowRight className="h-3.5 w-3.5 text-slate-500 group-hover:text-emerald-400 group-hover:translate-x-0.5 transition" />
              </div>
            </Link>
          ))}
        </div>
      </div>
    </AppLayout>
  );
}
