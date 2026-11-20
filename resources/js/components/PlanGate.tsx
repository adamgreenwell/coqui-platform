import { Link, usePage } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * Plan features shared by HandleInertiaRequests (`plan` prop). Which plan
 * includes which feature is decided on the server by the Pennant definitions;
 * the client only reads the resulting flags, and the server enforces them.
 */
export type PlanFeatureName =
  | 'content-briefs'
  | 'backlink-intelligence'
  | 'traffic-attribution'
  | 'white-label-reports'
  | 'priority-queue'
  | 'runnr-smoke-tests'
  | 'bot-telemetry';

export interface SharedPlan {
  tier: string;
  features: Partial<Record<PlanFeatureName, boolean>>;
  billingUrl: string;
}

export function usePlan() {
  const { props } = usePage<{ plan?: SharedPlan | null }>();
  const plan = props.plan ?? null;

  return {
    plan,
    billingUrl: plan?.billingUrl ?? '/workspaces',
    has: (feature: PlanFeatureName): boolean => plan?.features[feature] === true,
  };
}

/** Small "Upgrade" pill shown on locked links and buttons. */
export function UpgradePill() {
  return (
    <span className="inline-flex items-center gap-0.5 rounded-full border border-amber-500/40 bg-amber-500/10 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-amber-300">
      <Lock className="h-2.5 w-2.5" />
      Upgrade
    </span>
  );
}

interface FeatureLinkProps {
  feature: PlanFeatureName;
  href: string;
  className?: string;
  title?: string;
  /** Show the "Upgrade" pill when locked (off for compact icon links). */
  pill?: boolean;
  children: ReactNode;
}

/**
 * A link to a gated page. Without the feature it points at the workspace's
 * billing page and carries an upgrade pill, so people see what they'd get
 * instead of landing on an error.
 */
export function FeatureLink({ feature, href, className = '', title, pill = true, children }: FeatureLinkProps) {
  const { has, billingUrl } = usePlan();

  if (has(feature)) {
    return (
      <Link href={href} className={className} title={title}>
        {children}
      </Link>
    );
  }

  return (
    <Link href={billingUrl} className={`${className} opacity-75`} title="Upgrade your plan to unlock this">
      {children}
      {pill && <UpgradePill />}
    </Link>
  );
}

interface UpgradeNoticeProps {
  title: string;
  description: string;
  compact?: boolean;
}

/** Stands in for a gated card, form or action with an upgrade prompt. */
export function UpgradeNotice({ title, description, compact = false }: UpgradeNoticeProps) {
  const { billingUrl } = usePlan();

  return (
    <div
      className={`rounded-lg border border-amber-500/30 bg-amber-500/5 ${compact ? 'p-3' : 'p-4'} flex items-start gap-3`}
    >
      <Lock className="h-4 w-4 text-amber-300 mt-0.5 shrink-0" />
      <div className="flex-1 space-y-1">
        <div className="text-xs font-semibold text-amber-200">{title}</div>
        <p className="text-[11px] text-slate-400">{description}</p>
        <Link
          href={billingUrl}
          className="inline-flex items-center gap-1 mt-1 text-[11px] font-semibold text-amber-300 hover:text-amber-200 transition"
        >
          See plans &amp; upgrade →
        </Link>
      </div>
    </div>
  );
}
