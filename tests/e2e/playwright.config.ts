import { defineConfig, devices } from '@playwright/test';

/**
 * The one-time secret on the bundle's own rendered pages (make test-e2e): PHPUnit writes the answers to fixtures/
 * (NOWO_E2E_FIXTURES), server.mjs serves them with UiKit's public assets, Chromium runs the real runtimes.
 */
export default defineConfig({
  testDir: '.',
  testMatch: '*.spec.ts',
  reporter: 'list',
  use: { baseURL: 'http://127.0.0.1:8399', ...devices['Desktop Chrome'] },
  webServer: { command: 'node server.mjs', url: 'http://127.0.0.1:8399/health', reuseExistingServer: false },
});
