import { usePage } from '@inertiajs/react';

/**
 * Feature flags shared to every Inertia page by HandleInertiaRequests.
 */
export interface SharedFeatures {
  runnrIntegration: boolean;
}

export function useFeatures(): SharedFeatures {
  const { props } = usePage<{ features?: Partial<SharedFeatures> }>();

  return {
    runnrIntegration: props.features?.runnrIntegration === true,
  };
}
