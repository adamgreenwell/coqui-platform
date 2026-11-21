import { beforeEach, describe, expect, it, vi } from 'vitest';

const page = { props: {} as Record<string, unknown> };

vi.mock('@inertiajs/react', () => ({
  usePage: () => page,
}));

import { useFeatures } from './features';

describe('useFeatures', () => {
  beforeEach(() => {
    page.props = {};
  });

  it('turns a flag on only when the server shares exactly true', () => {
    page.props = { features: { runnrIntegration: true } };
    expect(useFeatures().runnrIntegration).toBe(true);
  });

  it('keeps flags off when they are missing or not booleans', () => {
    expect(useFeatures().runnrIntegration).toBe(false);

    page.props = { features: { runnrIntegration: 'true' } };
    expect(useFeatures().runnrIntegration).toBe(false);

    page.props = { features: null };
    expect(useFeatures().runnrIntegration).toBe(false);
  });
});
