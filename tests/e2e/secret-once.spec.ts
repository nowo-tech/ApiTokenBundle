import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';

/**
 * Contract §6.1 / §8.1 with UiKit's secret_once (audit ZAB-S3-L1): after issuing, the secret is in
 * document.documentElement.outerHTML exactly once (list page with the dialog opened on load, and the remote dialog
 * fragment); Copy puts it on the clipboard; closing the dialog or pagehide leaves it 0 times.
 */
const secretOf = (file: string): string => readFileSync(new URL(`./fixtures/${file}.secret`, import.meta.url), 'utf8');

async function count(page: Page, secret: string): Promise<number> {
  return page.evaluate((s) => document.documentElement.outerHTML.split(s).length - 1, secret);
}

async function upgraded(page: Page): Promise<void> {
  await expect(page.locator('[data-nowo-ui-secret-once-controls]')).toBeVisible();
}

test.use({ permissions: ['clipboard-read', 'clipboard-write'] });

test('page: once after issuing, copy works, 0 after closing the dialog', async ({ page }) => {
  const secret = secretOf('page.html');
  await page.goto('/page.html');
  await expect(page.locator('dialog#nowo-api-token-created')).toHaveAttribute('open', '');
  await upgraded(page);
  expect(await count(page, secret)).toBe(1);

  await page.locator('[data-nowo-ui-secret-once-copy]').click();
  await expect(page.locator('[data-nowo-ui-secret-once-status]')).not.toBeEmpty();
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(secret);
  expect(await count(page, secret)).toBe(1);

  await page.evaluate(() => (document.getElementById('nowo-api-token-created') as HTMLDialogElement).close());
  await expect(page.locator('dialog#nowo-api-token-created')).not.toHaveAttribute('open', '');
  await expect.poll(() => count(page, secret)).toBe(0);
});

test('page: 0 after pagehide', async ({ page }) => {
  const secret = secretOf('page.html');
  await page.goto('/page.html');
  await upgraded(page);
  expect(await count(page, secret)).toBe(1);
  await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide', { persisted: true })));
  await expect.poll(() => count(page, secret)).toBe(0);
});

test('remote dialog fragment: once when inserted, copy works, 0 after closing', async ({ page }) => {
  const secret = secretOf('fragment.html');
  await page.goto('/list.html');
  expect(await count(page, secret)).toBe(0);
  const html = await (await page.request.get('/fragment.html')).text();
  await page.evaluate((fragment) => {
    const dialog = document.createElement('dialog');
    dialog.id = 'e2e-remote-dialog';
    document.body.append(dialog);
    dialog.innerHTML = fragment;
    dialog.showModal();
    dialog.dispatchEvent(new CustomEvent('nowo-ui:dialog-loaded', { bubbles: true }));
  }, html);
  await upgraded(page);
  expect(await count(page, secret)).toBe(1);

  await page.locator('[data-nowo-ui-secret-once-copy]').click();
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(secret);

  await page.evaluate(() => (document.getElementById('e2e-remote-dialog') as HTMLDialogElement).close());
  await expect.poll(() => count(page, secret)).toBe(0);
});
