import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * REQ-DEMO-013: committed marketing screenshot. Full demo frame: masthead, the bundle status table in situ and the
 * Symfony WebProfiler toolbar. `make demo-screenshots` runs this and converts the PNG to WebP.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('API Token screenshots (full demo frame)', () => {
  test('overview: masthead, bundle status and profiler', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');
    await expect(page.getByTestId('demo-masthead')).toBeVisible();
    const status = page.getByTestId('demo-status');
    await expect(status).toBeVisible();
    await expect(status).toContainText('NowoApiTokenBundle');
    // Fit the viewport to the content so the profiler toolbar (fixed at the bottom) sits right under it.
    const bottom = await status.evaluate((el) => Math.ceil(el.getBoundingClientRect().bottom + window.scrollY));
    await page.setViewportSize({ width: 1280, height: Math.max(bottom + 110, 420) });
    await page.locator('.sf-toolbar').first().waitFor({ state: 'visible', timeout: 15000 }).catch(() => undefined);
    await page.screenshot({ path: resolve(outDir, 'overview.png') });
  });
});
