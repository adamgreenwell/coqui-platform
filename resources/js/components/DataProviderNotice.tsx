import { usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';

/**
 * Shown on keyword, ranking and backlink pages when this server has no SEO
 * data provider set up, so empty results come with a reason. Shared by
 * HandleInertiaRequests as `dataProviders.seoData`.
 */
export default function DataProviderNotice() {
  const { props } = usePage<{ dataProviders?: { seoData?: boolean } }>();

  if (props.dataProviders?.seoData !== false) {
    return null;
  }

  return (
    <div
      role="status"
      className="flex items-start gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-xs text-amber-200"
    >
      <AlertTriangle className="h-4 w-4 shrink-0 text-amber-400 mt-0.5" />
      <div className="space-y-1">
        <p className="font-semibold text-amber-100">SEO data isn&apos;t set up yet</p>
        <p>
          Keyword research, rank checks and backlink lookups need an SEO data provider, and this server doesn&apos;t
          have one connected. Anything already saved is still shown. An administrator can add the data provider
          credentials in the server settings.
        </p>
      </div>
    </div>
  );
}
