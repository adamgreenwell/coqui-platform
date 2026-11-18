import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Building2, Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import AppLayout from '../../layouts/AppLayout';

export default function WorkspaceCreate() {
  const { data, setData, post, processing, errors } = useForm({
    name: '',
    slug: '',
  });

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    post('/workspaces');
  };

  return (
    <AppLayout>
      <Head title="Create Workspace - Coqui Platform" />

      <div className="max-w-xl mx-auto space-y-6">
        <Link
          href="/workspaces"
          className="inline-flex items-center gap-1.5 text-xs text-slate-400 hover:text-white transition"
        >
          <ArrowLeft className="h-3.5 w-3.5" />
          <span>Back to workspaces</span>
        </Link>

        <div className="rounded-2xl border border-slate-800 bg-slate-900/60 p-6 sm:p-8">
          <div className="flex items-center gap-3 mb-6">
            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400">
              <Building2 className="h-5 w-5" />
            </div>
            <div>
              <h1 className="text-lg font-bold text-white">Create a New Workspace</h1>
              <p className="text-xs text-slate-400">
                Workspaces isolate projects, crawl data, team members, and API tokens.
              </p>
            </div>
          </div>

          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label htmlFor="name" className="block text-xs font-medium text-slate-300">
                Workspace Name
              </label>
              <input
                id="name"
                type="text"
                required
                value={data.name}
                onChange={(e) => setData('name', e.target.value)}
                className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500 transition"
                placeholder="Acme Corp"
              />
              {errors.name && <p className="mt-1 text-xs text-red-400">{errors.name}</p>}
            </div>

            <div>
              <label htmlFor="slug" className="block text-xs font-medium text-slate-300">
                Custom URL Slug <span className="text-slate-500">(Optional)</span>
              </label>
              <div className="mt-1 flex rounded-lg shadow-sm">
                <span className="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-slate-700 bg-slate-800 text-slate-400 text-xs">
                  coquiseo.com/
                </span>
                <input
                  id="slug"
                  type="text"
                  value={data.slug}
                  onChange={(e) => setData('slug', e.target.value)}
                  className="block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-r-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50 focus:border-emerald-500 transition"
                  placeholder="acme-corp"
                />
              </div>
              {errors.slug && <p className="mt-1 text-xs text-red-400">{errors.slug}</p>}
              <p className="mt-1 text-[11px] text-slate-500">
                Lowercase letters, numbers, and hyphens only. Left blank, it will be generated from the name.
              </p>
            </div>

            <div className="pt-4 flex items-center justify-end gap-3 border-t border-slate-800">
              <Link
                href="/workspaces"
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
                <span>{processing ? 'Creating...' : 'Create Workspace'}</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </AppLayout>
  );
}
