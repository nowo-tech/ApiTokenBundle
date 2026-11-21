import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * REQ-DEMO-013: committed marketing screenshot. Full demo frame: the demo platform shell and the bundle status table in situ
 * (the Symfony WebProfiler toolbar is hidden). `make demo-screenshots` runs this and converts the PNG to WebP.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('API Token screenshots (full demo frame)', () => {
  test('overview: platform shell and bundle status', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');
    await expect(page.getByTestId('demo-platform')).toBeVisible();
    const status = page.getByTestId('demo-status');
    await expect(status).toBeVisible();
    await expect(status).toContainText('NowoApiTokenBundle');
    await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
    await page.screenshot({ path: resolve(outDir, 'overview.png') });
  });
});
