import { Head, Link, router, useForm } from '@inertiajs/react';
import {
  AlertTriangle,
  ArrowLeft,
  Check,
  Copy,
  Key,
  Laptop,
  Plus,
  Shield,
  Trash2,
} from 'lucide-react';
import { type FormEvent, useState } from 'react';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface TokenItem {
  id: string;
  name: string;
  abilities: string[] | null;
  // An older token not tied to a workspace: it works in all of yours.
  allWorkspaces?: boolean;
  lastUsedAt?: string | null;
  createdAt?: string | null;
}

interface WorkspaceTokensProps {
  workspace: WorkspaceSummary;
  tokens: TokenItem[];
  canCreateTokens?: boolean;
  plainTextToken?: string | null;
}

export default function WorkspaceTokens({
  workspace,
  tokens,
  canCreateTokens = true,
  plainTextToken,
}: WorkspaceTokensProps) {
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [copied, setCopied] = useState(false);

  const { data, setData, post, processing, errors, reset } = useForm({
    name: '',
    abilities: ['crawls:sync', 'projects:read'],
  });

  const handleCreate = (e: FormEvent) => {
    e.preventDefault();
    post(`/workspaces/${workspace.id}/tokens`, {
      onSuccess: () => {
        setCreateModalOpen(false);
        reset();
      },
    });
  };

  const handleRevoke = (token: TokenItem) => {
    if (confirm(`Revoke token "${token.name}"? Coqui Desktop instances using this token will stop syncing.`)) {
      router.delete(`/workspaces/${workspace.id}/tokens/${token.id}`);
    }
  };

  const handleCopy = (tokenStr: string) => {
    navigator.clipboard.writeText(tokenStr);
    setCopied(true);
    setTimeout(() => setCopied(false), 2500);
  };

  const toggleAbility = (ability: string) => {
    if (data.abilities.includes(ability)) {
      setData(
        'abilities',
        data.abilities.filter((a) => a !== ability)
      );
    } else {
      setData('abilities', [...data.abilities, ability]);
    }
  };

  return (
    <AppLayout currentWorkspace={workspace}>
      <Head title={`${workspace.name} - Desktop Sync Tokens`} />

      <div className="space-y-6">
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div className="space-y-1">
            <Link
              href={`/workspaces/${workspace.id}`}
              className="inline-flex items-center gap-1.5 text-xs text-slate-400 hover:text-white transition"
            >
              <ArrowLeft className="h-3.5 w-3.5" />
              <span>Back to overview</span>
            </Link>
            <h1 className="text-2xl font-bold tracking-tight text-white flex items-center gap-2">
              <Key className="h-6 w-6 text-emerald-400" />
              <span>Desktop Sync Tokens</span>
            </h1>
            <p className="text-xs text-slate-400">
              Personal access tokens for authenticating Coqui Desktop (Rust / Tauri) and CLI synchronization.
            </p>
          </div>

          {canCreateTokens && (
            <button
              type="button"
              onClick={() => setCreateModalOpen(true)}
              className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition shadow-lg shadow-emerald-500/10 self-start sm:self-auto cursor-pointer"
            >
              <Plus className="h-4 w-4" />
              <span>New Desktop Token</span>
            </button>
          )}
        </div>

        {/* Revealed Token Alert */}
        {plainTextToken && (
          <div className="rounded-2xl border border-emerald-500/40 bg-emerald-950/20 p-5 space-y-3">
            <div className="flex items-center gap-2 text-emerald-400 text-sm font-semibold">
              <Check className="h-5 w-5" />
              <span>New Token Generated — Copy It Now</span>
            </div>
            <p className="text-xs text-slate-300">
              This token will only be shown once. Please copy and paste it into Coqui Desktop or your environment.
            </p>
            <div className="flex items-center gap-2">
              <div className="flex-1 bg-slate-950 border border-slate-700 px-3 py-2 rounded-lg font-mono text-xs text-emerald-300 break-all select-all">
                {plainTextToken}
              </div>
              <button
                type="button"
                onClick={() => handleCopy(plainTextToken)}
                className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition shrink-0 cursor-pointer"
              >
                {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                <span>{copied ? 'Copied!' : 'Copy'}</span>
              </button>
            </div>
          </div>
        )}

        {/* Tokens List */}
        <div className="rounded-xl border border-slate-800 bg-slate-900/60 overflow-hidden shadow-sm">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-800 text-left text-xs">
              <thead className="bg-slate-950/60 text-slate-400 uppercase tracking-wider font-semibold text-[11px]">
                <tr>
                  <th scope="col" className="px-6 py-3.5">
                    Token Name
                  </th>
                  <th scope="col" className="px-6 py-3.5">
                    Abilities
                  </th>
                  <th scope="col" className="px-6 py-3.5">
                    Created
                  </th>
                  <th scope="col" className="px-6 py-3.5">
                    Last Used
                  </th>
                  <th scope="col" className="px-6 py-3.5 text-right">
                    Revoke
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800 text-slate-200">
                {tokens.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-6 py-8 text-center text-slate-500">
                      No active desktop tokens. Create one to link Coqui Desktop.
                    </td>
                  </tr>
                ) : (
                  tokens.map((token) => (
                    <tr key={token.id} className="hover:bg-slate-800/30 transition">
                      <td className="px-6 py-4 font-semibold text-white">
                        <div className="flex items-center gap-2">
                          <Laptop className="h-4 w-4 text-slate-400" />
                          <span>{token.name}</span>
                          {token.allWorkspaces && (
                            <span
                              className="px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-300 border border-amber-500/30 text-[10px] font-medium"
                              title="Created before tokens were tied to one workspace, so it works in all of your workspaces. Revoke it and create a new one to limit it to this workspace."
                            >
                              All workspaces
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <div className="flex flex-wrap gap-1">
                          {token.abilities && token.abilities.length > 0 ? (
                            token.abilities.map((ability) => (
                              <span
                                key={ability}
                                className="px-2 py-0.5 rounded bg-slate-800 text-slate-300 font-mono text-[10px] border border-slate-700"
                              >
                                {ability}
                              </span>
                            ))
                          ) : (
                            <span className="text-slate-500 text-[10px]">All</span>
                          )}
                        </div>
                      </td>
                      <td className="px-6 py-4 text-slate-400">
                        {token.createdAt
                          ? new Date(token.createdAt).toLocaleDateString()
                          : '—'}
                      </td>
                      <td className="px-6 py-4 text-slate-400">
                        {token.lastUsedAt ? (
                          <span className="text-emerald-400">
                            {new Date(token.lastUsedAt).toLocaleString()}
                          </span>
                        ) : (
                          <span className="text-slate-500">Never</span>
                        )}
                      </td>
                      <td className="px-6 py-4 text-right">
                        <button
                          type="button"
                          onClick={() => handleRevoke(token)}
                          className="p-1.5 text-slate-400 hover:text-red-400 rounded transition cursor-pointer"
                          title="Revoke token"
                        >
                          <Trash2 className="h-4 w-4" />
                        </button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>

        {/* Quickstart Integration Guide */}
        <div className="rounded-2xl border border-slate-800 bg-slate-900/40 p-6 space-y-4">
          <h3 className="text-sm font-semibold text-white flex items-center gap-2">
            <Shield className="h-4 w-4 text-emerald-400" />
            <span>Linking Coqui Desktop</span>
          </h3>
          <p className="text-xs text-slate-400">
            Coqui Desktop syncs completed local crawls to the platform using Sanctum tokens. In your Coqui Desktop client or CLI environment, export your token:
          </p>
          <div className="bg-slate-950 border border-slate-800 rounded-lg p-3 font-mono text-xs text-slate-300 space-y-1">
            <div className="text-slate-500"># Set token for automatic sync</div>
            <div>export COQUI_API_TOKEN=&quot;&lt;your-token-here&gt;&quot;</div>
            <div>export COQUI_API_URL=&quot;https://coquiseo.com/api/v1&quot;</div>
          </div>
        </div>

        {/* Create Token Modal */}
        {createModalOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
            <div className="w-full max-w-md rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl">
              <div className="flex items-center justify-between mb-4">
                <h3 className="text-base font-bold text-white flex items-center gap-2">
                  <Key className="h-5 w-5 text-emerald-400" />
                  <span>Generate Desktop Sync Token</span>
                </h3>
                <button
                  type="button"
                  onClick={() => setCreateModalOpen(false)}
                  className="text-slate-400 hover:text-white text-xs"
                >
                  ✕
                </button>
              </div>

              <form onSubmit={handleCreate} className="space-y-4">
                <div>
                  <label htmlFor="token-name" className="block text-xs font-medium text-slate-300">
                    Device / Token Name
                  </label>
                  <input
                    id="token-name"
                    type="text"
                    required
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                    placeholder="e.g. MacBook Pro M3 or Desktop Rig"
                  />
                  {errors.name && <p className="mt-1 text-xs text-red-400">{errors.name}</p>}
                </div>

                <div>
                  <label className="block text-xs font-medium text-slate-300 mb-2">
                    Scoped Abilities
                  </label>
                  <div className="space-y-2">
                    <label className="flex items-start gap-2.5 p-2 rounded-lg border border-slate-800 bg-slate-950/60 cursor-pointer hover:border-slate-700">
                      <input
                        type="checkbox"
                        checked={data.abilities.includes('crawls:sync')}
                        onChange={() => toggleAbility('crawls:sync')}
                        className="mt-0.5 h-4 w-4 rounded border-slate-700 bg-slate-900 text-emerald-500 focus:ring-emerald-500/40"
                      />
                      <div>
                        <div className="text-xs font-semibold text-white">crawls:sync</div>
                        <div className="text-[11px] text-slate-400">
                          Upload chunks and finalize crawl snapshots from Coqui Desktop.
                        </div>
                      </div>
                    </label>

                    <label className="flex items-start gap-2.5 p-2 rounded-lg border border-slate-800 bg-slate-950/60 cursor-pointer hover:border-slate-700">
                      <input
                        type="checkbox"
                        checked={data.abilities.includes('projects:read')}
                        onChange={() => toggleAbility('projects:read')}
                        className="mt-0.5 h-4 w-4 rounded border-slate-700 bg-slate-900 text-emerald-500 focus:ring-emerald-500/40"
                      />
                      <div>
                        <div className="text-xs font-semibold text-white">projects:read</div>
                        <div className="text-[11px] text-slate-400">
                          Read project configurations and domains for desktop sync targeting.
                        </div>
                      </div>
                    </label>

                    <label className="flex items-start gap-2.5 p-2 rounded-lg border border-slate-800 bg-slate-950/60 cursor-pointer hover:border-slate-700">
                      <input
                        type="checkbox"
                        checked={data.abilities.includes('projects:write')}
                        onChange={() => toggleAbility('projects:write')}
                        className="mt-0.5 h-4 w-4 rounded border-slate-700 bg-slate-900 text-emerald-500 focus:ring-emerald-500/40"
                      />
                      <div>
                        <div className="text-xs font-semibold text-white">projects:write</div>
                        <div className="text-[11px] text-slate-400">
                          Register new projects directly from Coqui Desktop.
                        </div>
                      </div>
                    </label>

                    <label className="flex items-start gap-2.5 p-2 rounded-lg border border-slate-800 bg-slate-950/60 cursor-pointer hover:border-slate-700">
                      <input
                        type="checkbox"
                        checked={data.abilities.includes('crawls:read')}
                        onChange={() => toggleAbility('crawls:read')}
                        className="mt-0.5 h-4 w-4 rounded border-slate-700 bg-slate-900 text-emerald-500 focus:ring-emerald-500/40"
                      />
                      <div>
                        <div className="text-xs font-semibold text-white">crawls:read</div>
                        <div className="text-[11px] text-slate-400">
                          Inspect crawl history and issue breakdowns from the desktop app.
                        </div>
                      </div>
                    </label>

                    <label className="flex items-start gap-2.5 p-2 rounded-lg border border-slate-800 bg-slate-950/60 cursor-pointer hover:border-slate-700">
                      <input
                        type="checkbox"
                        checked={data.abilities.includes('mcp:use')}
                        onChange={() => toggleAbility('mcp:use')}
                        className="mt-0.5 h-4 w-4 rounded border-slate-700 bg-slate-900 text-emerald-500 focus:ring-emerald-500/40"
                      />
                      <div>
                        <div className="text-xs font-semibold text-white">mcp:use</div>
                        <div className="text-[11px] text-slate-400">
                          Call the Coqui MCP server from AI assistants. Live keyword lookups bill this workspace.
                        </div>
                      </div>
                    </label>
                  </div>
                  {errors.abilities && (
                    <p className="mt-1 text-xs text-red-400">{errors.abilities}</p>
                  )}
                </div>

                <div className="pt-3 flex items-center justify-end gap-2 border-t border-slate-800">
                  <button
                    type="button"
                    onClick={() => setCreateModalOpen(false)}
                    className="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-400 hover:text-white"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={processing || data.abilities.length === 0}
                    className="px-4 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition disabled:opacity-50 cursor-pointer"
                  >
                    {processing ? 'Generating...' : 'Generate Token'}
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
