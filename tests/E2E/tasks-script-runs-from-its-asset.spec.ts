import { expect, test, type Page } from '@playwright/test';

/**
 * Tasks's script is a static asset now, not a string inside the PHP
 * handler: the page must still run it. Nothing else exercises this window, so
 * an asset that failed to load would leave it inert without a single red test.
 */
const EMAIL = process.env.CMS_E2E_EMAIL ?? 'owner@semitexa.test';
const PASSWORD = process.env.CMS_E2E_PASSWORD ?? 'walkthrough-2026';

async function signIn(page: Page): Promise<void> {
    await page.goto('/os/login', { waitUntil: 'domcontentloaded' });
    await page.locator('input[name="email"]').fill(EMAIL);
    await page.locator('input[name="password"]').fill(PASSWORD);
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.locator('form button[type="submit"], form input[type="submit"]').first().click(),
    ]);
}

test('the Tasks window runs its script from the asset', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await signIn(page);

    const response = await page.goto('/os/app/tasks', { waitUntil: 'domcontentloaded' });
    expect(response?.status()).toBe(200);

    // The count line is written by the script once the list is fetched.
    await expect(page.locator('#sub')).toHaveText(/\d+ tasks? · \d+ open/);

    // The theme sync ran from its asset too.
    await expect(page.locator('html')).toHaveAttribute('data-mode', /^(light|dark)$/);
    const inline = await page.locator('script:not([src]):not([type="application/json"])').count();
    expect(inline, 'no executable inline script is left').toBe(0);
    expect(errors).toEqual([]);
});
