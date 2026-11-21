import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// Separate from vite.config.ts: the Laravel Vite plugin refuses to start
// when CI is set, and unit tests don't need it.
export default defineConfig({
  plugins: [react()],
  test: {
    include: ['resources/js/**/*.test.{ts,tsx}'],
  },
});
