import { Link, router, usePage } from '@inertiajs/react';
import {
  Building2,
  ChevronDown,
  Key,
  Laptop,
  LayoutDashboard,
  LogOut,
  Plus,
  Users,
} from 'lucide-react';
import { type ReactNode, useState } from 'react';

export interface WorkspaceSummary {
  id: number;
  name: string;
  slug: string;
  plan_tier?: string;
  planTier?: string;
}

interface PageProps {
  auth?: {
    user: {
      id: number;
      name: string;
      email: string;
    } | null;
  };
  flash?: {
    success?: string | null;
    error?: string | null;
    status?: string | null;
  };
  [key: string]: unknown;
}

interface AppLayoutProps {
  currentWorkspace?: WorkspaceSummary | null;
  allWorkspaces?: WorkspaceSummary[];
  children: ReactNode;
}

export default function AppLayout({
  currentWorkspace,
  allWorkspaces = [],
  children,
}: AppLayoutProps) {
  const { props } = usePage<PageProps>();
  const user = props.auth?.user;
  const flash = props.flash;

  const [switcherOpen, setSwitcherOpen] = useState(false);
  const [userMenuOpen, setUserMenuOpen] = useState(false);

  const handleLogout = () => {
    router.post('/logout');
  };

  return (
    <div className="min-h-screen bg-slate-950 text-slate-100 flex flex-col font-sans selection:bg-emerald-500/30 selection:text-emerald-200">
      {/* Top Navbar */}
      <header className="border-b border-slate-800 bg-slate-900/80 backdrop-blur sticky top-0 z-40">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
          <div className="flex items-center gap-6">
            <Link
              href="/dashboard"
              className="flex items-center gap-2 text-lg font-bold tracking-tight text-white hover:text-emerald-400 transition"
            >
              <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm font-black">
                CQ
              </span>
              <span>Coqui Platform</span>
            </Link>

            {/* Workspace Switcher */}
            {currentWorkspace && (
              <div className="relative">
                <button
                  type="button"
                  onClick={() => setSwitcherOpen(!switcherOpen)}
                  className="flex items-center gap-2 rounded-md border border-slate-700 bg-slate-800/80 px-3 py-1.5 text-xs font-medium text-slate-200 hover:bg-slate-700 transition"
                >
                  <Building2 className="h-3.5 w-3.5 text-emerald-400" />
                  <span className="max-w-[140px] truncate">{currentWorkspace.name}</span>
                  <ChevronDown className="h-3 w-3 text-slate-400" />
                </button>

                {switcherOpen && (
                  <>
                    <div
                      className="fixed inset-0 z-10"
                      onClick={() => setSwitcherOpen(false)}
                    />
                    <div className="absolute left-0 mt-2 w-64 rounded-lg border border-slate-700 bg-slate-900 p-2 shadow-xl z-20">
                      <div className="px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        Switch Workspace
                      </div>
                      <div className="mt-1 space-y-1">
                        {allWorkspaces.map((ws) => (
                          <Link
                            key={ws.id}
                            href={`/workspaces/${ws.id}`}
                            onClick={() => setSwitcherOpen(false)}
                            className={`flex items-center justify-between rounded-md px-2.5 py-1.5 text-xs transition ${
                              ws.id === currentWorkspace.id
                                ? 'bg-emerald-500/10 text-emerald-400 font-medium'
                                : 'text-slate-300 hover:bg-slate-800'
                            }`}
                          >
                            <span className="truncate">{ws.name}</span>
                            <span className="text-[10px] uppercase font-mono px-1.5 py-0.5 rounded bg-slate-800 text-slate-400">
                              {ws.plan_tier || ws.planTier || 'free'}
                            </span>
                          </Link>
                        ))}
                      </div>
                      <div className="mt-2 border-t border-slate-800 pt-2">
                        <Link
                          href="/workspaces/create"
                          onClick={() => setSwitcherOpen(false)}
                          className="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-emerald-400 hover:bg-emerald-500/10 transition"
                        >
                          <Plus className="h-3.5 w-3.5" />
                          <span>New Workspace</span>
                        </Link>
                      </div>
                    </div>
                  </>
                )}
              </div>
            )}
          </div>

          {/* Navigation Links */}
          {currentWorkspace && (
            <nav className="hidden md:flex items-center gap-1">
              <Link
                href={`/workspaces/${currentWorkspace.id}`}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium text-slate-300 hover:text-white hover:bg-slate-800 transition"
              >
                <LayoutDashboard className="h-3.5 w-3.5 text-slate-400" />
                <span>Overview</span>
              </Link>
              <Link
                href={`/workspaces/${currentWorkspace.id}/members`}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium text-slate-300 hover:text-white hover:bg-slate-800 transition"
              >
                <Users className="h-3.5 w-3.5 text-slate-400" />
                <span>Members & RBAC</span>
              </Link>
              <Link
                href={`/workspaces/${currentWorkspace.id}/tokens`}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium text-slate-300 hover:text-white hover:bg-slate-800 transition"
              >
                <Laptop className="h-3.5 w-3.5 text-slate-400" />
                <span>Desktop Sync</span>
              </Link>
            </nav>
          )}

          {/* User Profile / Logout */}
          <div className="flex items-center gap-3">
            {user ? (
              <div className="relative">
                <button
                  type="button"
                  onClick={() => setUserMenuOpen(!userMenuOpen)}
                  className="flex items-center gap-2 rounded-full border border-slate-700 bg-slate-800 pl-3 pr-2 py-1 text-xs text-slate-200 hover:bg-slate-700 transition"
                >
                  <span className="max-w-[120px] truncate">{user.name}</span>
                  <div className="h-6 w-6 rounded-full bg-emerald-500/20 text-emerald-300 flex items-center justify-center font-bold text-xs">
                    {user.name.charAt(0).toUpperCase()}
                  </div>
                </button>

                {userMenuOpen && (
                  <>
                    <div
                      className="fixed inset-0 z-10"
                      onClick={() => setUserMenuOpen(false)}
                    />
                    <div className="absolute right-0 mt-2 w-52 rounded-lg border border-slate-700 bg-slate-900 p-2 shadow-xl z-20">
                      <div className="px-2.5 py-1.5">
                        <div className="text-xs font-medium text-white">{user.name}</div>
                        <div className="text-[11px] text-slate-400 truncate">{user.email}</div>
                      </div>
                      <div className="mt-1 border-t border-slate-800 pt-1">
                        <Link
                          href="/workspaces"
                          onClick={() => setUserMenuOpen(false)}
                          className="flex items-center gap-2 px-2.5 py-1.5 rounded text-xs text-slate-300 hover:bg-slate-800 hover:text-white transition"
                        >
                          <Building2 className="h-3.5 w-3.5 text-slate-400" />
                          <span>All Workspaces</span>
                        </Link>
                        <button
                          type="button"
                          onClick={handleLogout}
                          className="w-full flex items-center gap-2 px-2.5 py-1.5 rounded text-xs text-red-400 hover:bg-red-500/10 hover:text-red-300 transition text-left"
                        >
                          <LogOut className="h-3.5 w-3.5" />
                          <span>Log Out</span>
                        </button>
                      </div>
                    </div>
                  </>
                )}
              </div>
            ) : (
              <div className="flex items-center gap-2">
                <Link
                  href="/login"
                  className="text-xs font-medium text-slate-300 hover:text-white px-3 py-1.5 rounded transition"
                >
                  Log in
                </Link>
                <Link
                  href="/register"
                  className="text-xs font-medium bg-emerald-500 hover:bg-emerald-400 text-slate-950 px-3 py-1.5 rounded transition font-semibold"
                >
                  Sign up
                </Link>
              </div>
            )}
          </div>
        </div>
      </header>

      {/* Flash Messages */}
      {flash?.success && (
        <div className="bg-emerald-500/10 border-b border-emerald-500/30 px-4 py-2.5 text-center text-xs font-medium text-emerald-300">
          {flash.success}
        </div>
      )}
      {flash?.error && (
        <div className="bg-red-500/10 border-b border-red-500/30 px-4 py-2.5 text-center text-xs font-medium text-red-300">
          {flash.error}
        </div>
      )}
      {flash?.status && (
        <div className="bg-sky-500/10 border-b border-sky-500/30 px-4 py-2.5 text-center text-xs font-medium text-sky-300">
          {flash.status}
        </div>
      )}

      {/* Main Content */}
      <main className="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {children}
      </main>

      {/* Footer */}
      <footer className="border-t border-slate-800/60 bg-slate-950/60 py-6 text-center text-xs text-slate-500">
        <div className="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-2">
          <span>&copy; {new Date().getFullYear()} Coqui SEO Platform. Built on Laravel 13.</span>
          <div className="flex items-center gap-4 text-slate-400">
            <span className="flex items-center gap-1.5">
              <span className="h-2 w-2 rounded-full bg-emerald-400 inline-block animate-pulse" />
              Desktop Sync Ready
            </span>
          </div>
        </div>
      </footer>
    </div>
  );
}
