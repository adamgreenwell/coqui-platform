import { Head, Link, router } from '@inertiajs/react';
import {
  AlertCircle,
  ArrowLeft,
  Check,
  CheckCircle2,
  CreditCard,
  ExternalLink,
  Flame,
  HelpCircle,
  Layers,
  Receipt,
  Server,
  Shield,
  Sparkles,
  Zap,
} from 'lucide-react';
import { useState } from 'react';
import AppLayout, { type WorkspaceSummary } from '../../layouts/AppLayout';

interface PlanDetail {
  name: string;
  price_usd: number;
  tracked_keyword_limit: number;
  cloud_crawl_url_limit: number;
  monthly_dfs_budget_usd: number;
  features: string[];
}

interface WorkspaceBillingProps {
  workspace: {
    id: string;
    name: string;
    slug: string;
    plan_tier: string;
    has_runnr_addon: boolean;
    subscription_status: string;
    current_period_end: string | null;
  };
  usage: {
    keywords_count: number;
    keywords_limit: number;
    crawl_urls_limit: number;
    dfs_spend_usd: number;
    dfs_budget_usd: number;
    dfs_remaining_usd: number;
    runnr_entitled: boolean;
  };
  plans: Record<string, PlanDetail>;
  invoices: Array<{
    id: number;
    stripe_invoice_id: string;
    amount_paid_usd: number;
    status: string;
    invoice_pdf_url: string | null;
    paid_at: string | null;
    created_at: string;
  }>;
  canEdit: boolean;
  billingMode: 'stripe' | 'mock' | 'unconfigured';
}

export default function WorkspaceBilling({
  workspace,
  usage,
  plans,
  invoices,
  canEdit,
  billingMode,
}: WorkspaceBillingProps) {
  const [processingTier, setProcessingTier] = useState<string | null>(null);

  const handleUpgrade = (tier: string) => {
    setProcessingTier(tier);
    router.post(
      `/workspaces/${workspace.id}/billing/checkout`,
      { plan_tier: tier },
      {
        onFinish: () => setProcessingTier(null),
      }
    );
  };

  const handlePortal = () => {
    router.post(`/workspaces/${workspace.id}/billing/portal`);
  };

  const keywordPct = Math.min(100, Math.round((usage.keywords_count / Math.max(1, usage.keywords_limit)) * 100));
  const dfsPct = Math.min(100, Math.round((usage.dfs_spend_usd / Math.max(1, usage.dfs_budget_usd)) * 100));

  return (
    <AppLayout currentWorkspace={workspace}>
      <Head title={`${workspace.name} - Subscription Billing & Usage`} />

      <div className="space-y-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {/* Navigation & Header */}
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <Link
              href={`/workspaces/${workspace.id}`}
              className="p-2 rounded-lg bg-slate-900 border border-slate-800 text-slate-400 hover:text-white hover:border-slate-700 transition cursor-pointer"
            >
              <ArrowLeft className="h-4 w-4" />
            </Link>
            <div>
              <div className="flex items-center gap-2">
                <span className="p-1 rounded bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                  <CreditCard className="h-3.5 w-3.5" />
                </span>
                <h1 className="text-xl font-bold text-white tracking-tight">
                  Subscription Billing &amp; Usage Metering
                </h1>
                <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wider bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                  {workspace.plan_tier} Plan
                </span>
                {workspace.has_runnr_addon && (
                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-indigo-500/10 text-indigo-400 border border-indigo-500/30">
                    <CheckCircle2 className="h-3 w-3" /> Runnr Bundle
                  </span>
                )}
              </div>
              <p className="text-xs text-slate-400 mt-0.5">
                Manage your plan tier, DataForSEO spend guardrails, keyword quotas, and Stripe customer billing.
              </p>
            </div>
          </div>

          {canEdit && (
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={handlePortal}
                className="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition cursor-pointer"
              >
                <ExternalLink className="h-3.5 w-3.5 text-slate-400" />
                <span>Stripe Customer Portal</span>
              </button>
            </div>
          )}
        </div>

        {billingMode === 'unconfigured' && (
          <div className="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-xs text-amber-200">
            <AlertCircle className="h-4 w-4 shrink-0 text-amber-400" />
            <span>
              Online payments are not set up yet, so plan upgrades and the billing portal are unavailable.
            </span>
          </div>
        )}
        {billingMode === 'mock' && (
          <div className="flex items-start gap-2 rounded-lg border border-sky-500/30 bg-sky-500/10 px-4 py-3 text-xs text-sky-200">
            <AlertCircle className="h-4 w-4 shrink-0 text-sky-400" />
            <span>
              Stripe is not connected (no STRIPE_SECRET). Checkout and the portal return simulated sessions for local development.
            </span>
          </div>
        )}

        {/* Usage & Metering KPI Grid */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {/* DataForSEO Monthly Spend Guard */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-4 space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-400">Monthly DataForSEO Allowance</span>
              <span className="p-1 rounded bg-amber-500/10 text-amber-400 border border-amber-500/20">
                <Zap className="h-3.5 w-3.5" />
              </span>
            </div>
            <div>
              <div className="flex items-baseline justify-between text-xs">
                <span className="text-lg font-bold text-white font-mono">
                  ${usage.dfs_spend_usd.toFixed(2)}
                </span>
                <span className="text-slate-400 font-mono">
                  / ${usage.dfs_budget_usd.toFixed(2)}
                </span>
              </div>
              <div className="mt-2 w-full bg-slate-800 rounded-full h-1.5 overflow-hidden">
                <div
                  className={`h-full rounded-full transition-all ${
                    dfsPct > 80 ? 'bg-rose-500' : dfsPct > 50 ? 'bg-amber-500' : 'bg-emerald-500'
                  }`}
                  style={{ width: `${dfsPct}%` }}
                />
              </div>
            </div>
            <p className="text-[11px] text-slate-500">
              ${usage.dfs_remaining_usd.toFixed(2)} remaining this month. Resets on the 1st.
            </p>
          </div>

          {/* Tracked Keywords Meter */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-4 space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-400">Tracked Keywords</span>
              <span className="p-1 rounded bg-blue-500/10 text-blue-400 border border-blue-500/20">
                <Layers className="h-3.5 w-3.5" />
              </span>
            </div>
            <div>
              <div className="flex items-baseline justify-between text-xs">
                <span className="text-lg font-bold text-white font-mono">
                  {usage.keywords_count.toLocaleString()}
                </span>
                <span className="text-slate-400 font-mono">
                  / {usage.keywords_limit.toLocaleString()}
                </span>
              </div>
              <div className="mt-2 w-full bg-slate-800 rounded-full h-1.5 overflow-hidden">
                <div
                  className={`h-full rounded-full transition-all ${
                    keywordPct > 90 ? 'bg-rose-500' : keywordPct > 70 ? 'bg-amber-500' : 'bg-blue-500'
                  }`}
                  style={{ width: `${keywordPct}%` }}
                />
              </div>
            </div>
            <p className="text-[11px] text-slate-500">
              {Math.max(0, usage.keywords_limit - usage.keywords_count).toLocaleString()} slots available across all projects.
            </p>
          </div>

          {/* Cloud Crawl URL Cap */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-4 space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-400">Cloud Crawl URL Limit</span>
              <span className="p-1 rounded bg-purple-500/10 text-purple-400 border border-purple-500/20">
                <Sparkles className="h-3.5 w-3.5" />
              </span>
            </div>
            <div>
              <span className="text-lg font-bold text-white font-mono">
                {usage.crawl_urls_limit.toLocaleString()}
              </span>
              <span className="text-xs text-slate-400 block mt-0.5">URLs per audit</span>
            </div>
            <p className="text-[11px] text-slate-500">
              Desktop Sync engine has unlimited local crawl capabilities.
            </p>
          </div>

          {/* Runnr Hosting Entitlement */}
          <div className="rounded-xl border border-slate-800 bg-slate-900/60 p-4 space-y-3">
            <div className="flex items-center justify-between">
              <span className="text-xs font-medium text-slate-400">Runnr Hosting Perks</span>
              <span className="p-1 rounded bg-indigo-500/10 text-indigo-400 border border-indigo-500/20">
                <Server className="h-3.5 w-3.5" />
              </span>
            </div>
            <div>
              <span className={`text-base font-bold ${usage.runnr_entitled ? 'text-emerald-400' : 'text-slate-400'}`}>
                {usage.runnr_entitled ? 'Active & Unlocked' : 'Add-on Required'}
              </span>
              <span className="text-xs text-slate-400 block mt-0.5">
                Deploy Smoke &amp; Bot Telemetry
              </span>
            </div>
            <p className="text-[11px] text-slate-500">
              {usage.runnr_entitled
                ? 'Unlocked via connected Runnr project or Agency plan.'
                : 'Connect a Runnr-hosted project or get the add-on.'}
            </p>
          </div>
        </div>

        {/* Plan Tiers Grid */}
        <div className="space-y-3">
          <div>
            <h2 className="text-base font-semibold text-white">Available Plans &amp; Upgrades</h2>
            <p className="text-xs text-slate-400">
              Scale your SERP intelligence, cloud crawler quotas, and team seats with transparent pricing.
            </p>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">
            {/* Free Tier */}
            <div className={`rounded-xl border p-6 flex flex-col justify-between gap-6 transition ${
              workspace.plan_tier === 'free'
                ? 'border-emerald-500/50 bg-slate-900/90 ring-1 ring-emerald-500/30'
                : 'border-slate-800 bg-slate-900/50'
            }`}>
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <h3 className="text-lg font-bold text-white">Free / Desktop Sync</h3>
                  {workspace.plan_tier === 'free' && (
                    <span className="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                      Current Plan
                    </span>
                  )}
                </div>
                <div>
                  <span className="text-3xl font-extrabold text-white">$0</span>
                  <span className="text-xs text-slate-400 ml-1">/ month</span>
                </div>
                <p className="text-xs text-slate-400">
                  Ideal for solo creators utilizing the desktop crawl engine with basic cloud sync.
                </p>
                <ul className="space-y-2 text-xs text-slate-300 pt-2 border-t border-slate-800">
                  {plans.free?.features.map((f) => (
                    <li key={f} className="flex items-start gap-2">
                      <Check className="h-3.5 w-3.5 text-emerald-400 shrink-0 mt-0.5" />
                      <span>{f}</span>
                    </li>
                  ))}
                </ul>
              </div>

              <div>
                {workspace.plan_tier === 'free' ? (
                  <button
                    type="button"
                    disabled
                    className="w-full py-2 rounded-lg bg-slate-800 text-slate-500 font-medium text-xs cursor-default"
                  >
                    Active Plan
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={handlePortal}
                    disabled={processingTier !== null || !canEdit}
                    title="Cancel or change your subscription in the Stripe customer portal"
                    className="w-full py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-xs transition cursor-pointer"
                  >
                    Downgrade via Billing Portal
                  </button>
                )}
              </div>
            </div>

            {/* Personal Tier */}
            <div className={`rounded-xl border p-6 flex flex-col justify-between gap-6 transition ${
              workspace.plan_tier === 'personal'
                ? 'border-sky-500/60 bg-slate-900/90 ring-2 ring-sky-500/40'
                : 'border-slate-800 bg-slate-900/50'
            }`}>
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <h3 className="text-lg font-bold text-white">Personal</h3>
                  {workspace.plan_tier === 'personal' && (
                    <span className="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                      Current Plan
                    </span>
                  )}
                </div>
                <div>
                  <span className="text-3xl font-extrabold text-white">${plans.personal?.price_usd ?? 19}</span>
                  <span className="text-xs text-slate-400 ml-1">/ month</span>
                </div>
                <p className="text-xs text-slate-400">
                  For bloggers and individual site owners: the full Pro toolset, sized for one site.
                </p>
                <ul className="space-y-2 text-xs text-slate-300 pt-2 border-t border-slate-800">
                  {plans.personal?.features.map((f) => (
                    <li key={f} className="flex items-start gap-2">
                      <Check className="h-3.5 w-3.5 text-sky-400 shrink-0 mt-0.5" />
                      <span>{f}</span>
                    </li>
                  ))}
                </ul>
              </div>

              <div>
                {workspace.plan_tier === 'personal' ? (
                  <button
                    type="button"
                    disabled
                    className="w-full py-2 rounded-lg bg-sky-600/30 text-sky-300 font-medium text-xs cursor-default border border-sky-500/30"
                  >
                    Active Plan
                  </button>
                ) : workspace.plan_tier === 'free' ? (
                  <button
                    type="button"
                    onClick={() => handleUpgrade('personal')}
                    disabled={processingTier !== null}
                    className="w-full py-2 rounded-lg bg-sky-600 hover:bg-sky-500 text-white font-semibold text-xs transition cursor-pointer shadow-md shadow-sky-500/20"
                  >
                    {processingTier === 'personal' ? 'Redirecting to Stripe...' : 'Upgrade to Personal'}
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={handlePortal}
                    disabled={processingTier !== null || !canEdit}
                    title="Change your subscription in the Stripe customer portal"
                    className="w-full py-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium text-xs transition cursor-pointer"
                  >
                    Downgrade via Billing Portal
                  </button>
                )}
              </div>
            </div>

            {/* Pro Tier (Popular) */}
            <div className={`rounded-xl border p-6 flex flex-col justify-between gap-6 transition relative ${
              workspace.plan_tier === 'pro'
                ? 'border-indigo-500/60 bg-slate-900/90 ring-2 ring-indigo-500/40'
                : 'border-slate-700 bg-slate-900/70 shadow-lg'
            }`}>
              <div className="absolute -top-3 left-1/2 -translate-x-1/2">
                <span className="px-3 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-indigo-500 text-white shadow-md">
                  Most Popular
                </span>
              </div>

              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <h3 className="text-lg font-bold text-white">Pro Cloud</h3>
                  {workspace.plan_tier === 'pro' && (
                    <span className="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                      Current Plan
                    </span>
                  )}
                </div>
                <div>
                  <span className="text-3xl font-extrabold text-white">$49</span>
                  <span className="text-xs text-slate-400 ml-1">/ month</span>
                </div>
                <p className="text-xs text-slate-400">
                  Full intelligence suite: Live SERP content briefs, keyword gap, backlinks &amp; traffic attribution.
                </p>
                <ul className="space-y-2 text-xs text-slate-300 pt-2 border-t border-slate-800">
                  {plans.pro?.features.map((f) => (
                    <li key={f} className="flex items-start gap-2">
                      <Check className="h-3.5 w-3.5 text-indigo-400 shrink-0 mt-0.5" />
                      <span>{f}</span>
                    </li>
                  ))}
                </ul>
              </div>

              <div>
                {workspace.plan_tier === 'pro' ? (
                  <button
                    type="button"
                    disabled
                    className="w-full py-2 rounded-lg bg-indigo-600/30 text-indigo-300 font-medium text-xs cursor-default border border-indigo-500/30"
                  >
                    Active Plan
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={() => handleUpgrade('pro')}
                    disabled={processingTier !== null}
                    className="w-full py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-semibold text-xs transition cursor-pointer shadow-md shadow-indigo-500/20"
                  >
                    {processingTier === 'pro' ? 'Redirecting to Stripe...' : 'Upgrade to Pro'}
                  </button>
                )}
              </div>
            </div>

            {/* Agency Tier */}
            <div className={`rounded-xl border p-6 flex flex-col justify-between gap-6 transition ${
              workspace.plan_tier === 'agency'
                ? 'border-purple-500/60 bg-slate-900/90 ring-2 ring-purple-500/40'
                : 'border-slate-800 bg-slate-900/50'
            }`}>
              <div className="space-y-4">
                <div className="flex items-center justify-between">
                  <h3 className="text-lg font-bold text-white">Agency &amp; Enterprise</h3>
                  {workspace.plan_tier === 'agency' && (
                    <span className="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                      Current Plan
                    </span>
                  )}
                </div>
                <div>
                  <span className="text-3xl font-extrabold text-white">$199</span>
                  <span className="text-xs text-slate-400 ml-1">/ month</span>
                </div>
                <p className="text-xs text-slate-400">
                  Massive 10k keywords, 250k URLs, white-label client diffs, multi-seat &amp; native Runnr hosting perks.
                </p>
                <ul className="space-y-2 text-xs text-slate-300 pt-2 border-t border-slate-800">
                  {plans.agency?.features.map((f) => (
                    <li key={f} className="flex items-start gap-2">
                      <Check className="h-3.5 w-3.5 text-purple-400 shrink-0 mt-0.5" />
                      <span>{f}</span>
                    </li>
                  ))}
                </ul>
              </div>

              <div>
                {workspace.plan_tier === 'agency' ? (
                  <button
                    type="button"
                    disabled
                    className="w-full py-2 rounded-lg bg-purple-600/30 text-purple-300 font-medium text-xs cursor-default border border-purple-500/30"
                  >
                    Active Plan
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={() => handleUpgrade('agency')}
                    disabled={processingTier !== null}
                    className="w-full py-2 rounded-lg bg-purple-600 hover:bg-purple-500 text-white font-semibold text-xs transition cursor-pointer shadow-md shadow-purple-500/20"
                  >
                    {processingTier === 'agency' ? 'Redirecting to Stripe...' : 'Upgrade to Agency'}
                  </button>
                )}
              </div>
            </div>
          </div>
        </div>

        {/* Runnr Hosting Bundle Add-on Card */}
        <div className="rounded-xl border border-indigo-500/30 bg-gradient-to-r from-indigo-950/40 via-slate-900 to-slate-900 p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="space-y-1">
            <div className="flex items-center gap-2">
              <span className="p-1 rounded bg-indigo-500/20 text-indigo-400 border border-indigo-500/30">
                <Server className="h-4 w-4" />
              </span>
              <h3 className="text-sm font-semibold text-white">Runnr Hosting Moat Add-on</h3>
              <span className="text-xs font-bold text-indigo-400 font-mono">$29/mo</span>
              {workspace.has_runnr_addon && (
                <span className="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                  Active
                </span>
              )}
            </div>
            <p className="text-xs text-slate-300 max-w-2xl">
              Unlocks automated post-deploy SEO smoke testing with GitHub badges, access log bot &amp; AI crawler telemetry, and automated 301 remediation on any project.
            </p>
          </div>

          <div>
            {workspace.has_runnr_addon ? (
              <span className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 text-xs font-medium">
                <Check className="h-3.5 w-3.5" /> Included in Subscription
              </span>
            ) : (
              <button
                type="button"
                onClick={() => handleUpgrade('runnr_addon')}
                disabled={processingTier !== null}
                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white font-medium text-xs transition cursor-pointer shadow-md shadow-indigo-500/20"
              >
                <span>Add Runnr Hosting Bundle</span>
              </button>
            )}
          </div>
        </div>

        {/* Invoices History */}
        <div className="space-y-3">
          <div className="flex items-center gap-2">
            <Receipt className="h-4 w-4 text-slate-400" />
            <h2 className="text-sm font-semibold text-white">Billing History &amp; Receipts</h2>
          </div>

          <div className="rounded-xl border border-slate-800 bg-slate-900/60 overflow-hidden shadow-sm">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-950/60 border-b border-slate-800 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                <tr>
                  <th className="py-3 px-4">Invoice ID</th>
                  <th className="py-3 px-3">Date</th>
                  <th className="py-3 px-3 text-right">Amount</th>
                  <th className="py-3 px-3">Status</th>
                  <th className="py-3 px-3 text-right">Receipt</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-800/60 text-slate-300">
                {invoices.map((inv) => (
                  <tr key={inv.id} className="hover:bg-slate-800/30 transition">
                    <td className="py-3 px-4 font-mono text-white text-xs">{inv.stripe_invoice_id}</td>
                    <td className="py-3 px-3 text-slate-400">
                      {inv.paid_at ? new Date(inv.paid_at).toLocaleDateString() : '—'}
                    </td>
                    <td className="py-3 px-3 text-right font-mono font-medium text-white">
                      ${inv.amount_paid_usd.toFixed(2)}
                    </td>
                    <td className="py-3 px-3">
                      <span className="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wider bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                        {inv.status}
                      </span>
                    </td>
                    <td className="py-3 px-3 text-right">
                      {inv.invoice_pdf_url ? (
                        <a
                          href={inv.invoice_pdf_url}
                          target="_blank"
                          rel="noreferrer"
                          className="inline-flex items-center gap-1 text-indigo-400 hover:text-indigo-300 font-medium text-xs transition"
                        >
                          <span>PDF</span>
                          <ExternalLink className="h-3 w-3" />
                        </a>
                      ) : (
                        <span className="text-slate-500">—</span>
                      )}
                    </td>
                  </tr>
                ))}
                {invoices.length === 0 && (
                  <tr>
                    <td colSpan={5} className="py-8 text-center text-slate-500 text-xs">
                      No invoices recorded yet.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
