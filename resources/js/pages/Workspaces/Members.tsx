import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
  ArrowLeft,
  Check,
  Shield,
  Trash2,
  UserPlus,
  Users,
} from 'lucide-react';
import { type FormEvent, useState } from 'react';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface MemberItem {
  id: string;
  name: string;
  email: string;
  role: 'owner' | 'admin' | 'editor' | 'viewer' | string;
  joinedAt?: string | null;
  isOwner: boolean;
}

interface WorkspaceMembersProps {
  workspace: WorkspaceSummary;
  members: MemberItem[];
}

export default function WorkspaceMembers({
  workspace,
  members,
}: WorkspaceMembersProps) {
  const { props } = usePage<{ auth?: { user?: { id: string } | null } }>();
  const currentUserId = props.auth?.user?.id;

  const [inviteModalOpen, setInviteModalOpen] = useState(false);

  const { data, setData, post, processing, errors, reset } = useForm({
    email: '',
    role: 'editor',
  });

  const handleInvite = (e: FormEvent) => {
    e.preventDefault();
    post(`/workspaces/${workspace.id}/members`, {
      onSuccess: () => {
        setInviteModalOpen(false);
        reset();
      },
    });
  };

  const handleRoleChange = (memberId: string, newRole: string) => {
    router.put(`/workspaces/${workspace.id}/members/${memberId}`, {
      role: newRole,
    });
  };

  const handleRemoveMember = (member: MemberItem) => {
    if (confirm(`Are you sure you want to remove ${member.name} from this workspace?`)) {
      router.delete(`/workspaces/${workspace.id}/members/${member.id}`);
    }
  };

  const currentMember = members.find((m) => m.id === currentUserId);
  const canManage = currentMember?.role === 'owner' || currentMember?.role === 'admin';

  return (
    <AppLayout currentWorkspace={workspace}>
      <Head title={`${workspace.name} - Team Members`} />

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
              <Users className="h-6 w-6 text-sky-400" />
              <span>Team Members & Roles</span>
            </h1>
            <p className="text-xs text-slate-400">
              Manage member roles and workspace authorization. Owners and admins can invite, promote, or remove members.
            </p>
          </div>

          {canManage && (
            <button
              type="button"
              onClick={() => setInviteModalOpen(true)}
              className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition shadow-lg shadow-emerald-500/10 self-start sm:self-auto cursor-pointer"
            >
              <UserPlus className="h-4 w-4" />
              <span>Invite Member</span>
            </button>
          )}
        </div>

        {/* Members Table */}
        <div className="rounded-xl border border-slate-800 bg-slate-900/60 overflow-hidden shadow-sm">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-800 text-left text-xs">
              <thead className="bg-slate-950/60 text-slate-400 uppercase tracking-wider font-semibold text-[11px]">
                <tr>
                  <th scope="col" className="px-6 py-3.5">
                    Member
                  </th>
                  <th scope="col" className="px-6 py-3.5">
                    Role
                  </th>
                  <th scope="col" className="px-6 py-3.5">
                    Joined
                  </th>
                  {canManage && (
                    <th scope="col" className="px-6 py-3.5 text-right">
                      Actions
                    </th>
                  )}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800 text-slate-200">
                {members.map((member) => (
                  <tr key={member.id} className="hover:bg-slate-800/30 transition">
                    <td className="px-6 py-4">
                      <div className="flex items-center gap-3">
                        <div className="h-8 w-8 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 flex items-center justify-center font-bold text-xs">
                          {member.name.charAt(0).toUpperCase()}
                        </div>
                        <div>
                          <div className="font-semibold text-white flex items-center gap-2">
                            <span>{member.name}</span>
                            {member.id === currentUserId && (
                              <span className="text-[10px] text-slate-500 font-mono">(You)</span>
                            )}
                          </div>
                          <div className="text-slate-400 text-[11px] font-mono">{member.email}</div>
                        </div>
                      </div>
                    </td>
                    <td className="px-6 py-4">
                      {member.isOwner ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 border border-emerald-500/30 px-2.5 py-0.5 text-[11px] font-medium text-emerald-300">
                          <Shield className="h-3 w-3" />
                          Owner
                        </span>
                      ) : canManage ? (
                        <select
                          value={member.role}
                          onChange={(e) => handleRoleChange(member.id, e.target.value)}
                          className="bg-slate-950 border border-slate-700 rounded px-2.5 py-1 text-xs text-white focus:outline-none focus:ring-1 focus:ring-emerald-500"
                        >
                          <option value="admin">Admin</option>
                          <option value="editor">Editor</option>
                          <option value="viewer">Viewer</option>
                        </select>
                      ) : (
                        <span className="capitalize font-medium text-slate-300">
                          {member.role}
                        </span>
                      )}
                    </td>
                    <td className="px-6 py-4 text-slate-400">
                      {member.joinedAt
                        ? new Date(member.joinedAt).toLocaleDateString()
                        : 'Founding'}
                    </td>
                    {canManage && (
                      <td className="px-6 py-4 text-right">
                        {!member.isOwner && (
                          <button
                            type="button"
                            onClick={() => handleRemoveMember(member)}
                            className="p-1.5 text-slate-400 hover:text-red-400 rounded transition"
                            title="Remove member"
                          >
                            <Trash2 className="h-4 w-4" />
                          </button>
                        )}
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>

        {/* Role Permissions Reference */}
        <div className="rounded-xl border border-slate-800 bg-slate-900/30 p-5 space-y-3">
          <h3 className="text-xs font-semibold uppercase tracking-wider text-slate-400">
            Role Permission Matrix
          </h3>
          <div className="grid grid-cols-1 md:grid-cols-4 gap-3 text-xs">
            <div className="p-3 rounded-lg bg-slate-950/60 border border-slate-800">
              <div className="font-bold text-emerald-400 mb-1">Owner</div>
              <p className="text-slate-400 text-[11px]">
                Full control, billing, member management, workspace deletion, desktop tokens.
              </p>
            </div>
            <div className="p-3 rounded-lg bg-slate-950/60 border border-slate-800">
              <div className="font-bold text-purple-400 mb-1">Admin</div>
              <p className="text-slate-400 text-[11px]">
                Invite/remove members, manage projects, generate desktop tokens, edit settings.
              </p>
            </div>
            <div className="p-3 rounded-lg bg-slate-950/60 border border-slate-800">
              <div className="font-bold text-sky-400 mb-1">Editor</div>
              <p className="text-slate-400 text-[11px]">
                Create/edit projects, sync desktop crawl data, generate own desktop sync tokens.
              </p>
            </div>
            <div className="p-3 rounded-lg bg-slate-950/60 border border-slate-800">
              <div className="font-bold text-slate-400 mb-1">Viewer</div>
              <p className="text-slate-400 text-[11px]">
                Read-only access to projects, crawl data, SEO reports, and backlink monitors.
              </p>
            </div>
          </div>
        </div>

        {/* Invite Member Modal */}
        {inviteModalOpen && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
            <div className="w-full max-w-md rounded-2xl border border-slate-700 bg-slate-900 p-6 shadow-2xl">
              <div className="flex items-center justify-between mb-4">
                <h3 className="text-base font-bold text-white flex items-center gap-2">
                  <UserPlus className="h-5 w-5 text-emerald-400" />
                  <span>Invite Team Member</span>
                </h3>
                <button
                  type="button"
                  onClick={() => setInviteModalOpen(false)}
                  className="text-slate-400 hover:text-white text-xs"
                >
                  ✕
                </button>
              </div>

              <form onSubmit={handleInvite} className="space-y-4">
                <div>
                  <label htmlFor="invite-email" className="block text-xs font-medium text-slate-300">
                    User Email Address
                  </label>
                  <input
                    id="invite-email"
                    type="email"
                    required
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                    placeholder="teammate@example.com"
                  />
                  {errors.email && <p className="mt-1 text-xs text-red-400">{errors.email}</p>}
                </div>

                <div>
                  <label htmlFor="invite-role" className="block text-xs font-medium text-slate-300">
                    Role
                  </label>
                  <select
                    id="invite-role"
                    value={data.role}
                    onChange={(e) => setData('role', e.target.value)}
                    className="mt-1 block w-full px-3 py-2 bg-slate-950 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:ring-2 focus:ring-emerald-500/50"
                  >
                    <option value="editor">Editor (Can create projects and sync crawls)</option>
                    <option value="admin">Admin (Can manage team members and settings)</option>
                    <option value="viewer">Viewer (Read-only access)</option>
                  </select>
                  {errors.role && <p className="mt-1 text-xs text-red-400">{errors.role}</p>}
                </div>

                <div className="pt-3 flex items-center justify-end gap-2 border-t border-slate-800">
                  <button
                    type="button"
                    onClick={() => setInviteModalOpen(false)}
                    className="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-400 hover:text-white"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={processing}
                    className="px-4 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-400 text-slate-950 font-semibold text-xs transition disabled:opacity-50 cursor-pointer"
                  >
                    {processing ? 'Adding...' : 'Add Member'}
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
