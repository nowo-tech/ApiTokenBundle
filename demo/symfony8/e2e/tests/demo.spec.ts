import { test, expect } from '@playwright/test';

/** REQ-DEMO-013: the demo boots and the bundle status page renders (screenshots live in screenshots.spec.ts). */
test.describe('demo smoke', () => {
  test('home renders the UiKit shell and the bundle status table', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (m) => {
      if (m.type() === 'error') errors.push(m.text());
    });
    const response = await page.goto('/');
    expect(response?.status()).toBe(200);
    await expect(page.getByTestId('demo-masthead')).toBeVisible();
    await expect(page.getByTestId('demo-status')).toBeVisible();
    await expect(page.getByTestId('demo-status')).toContainText('NowoApiTokenBundle');
    expect(errors).toEqual([]);
  });
});
