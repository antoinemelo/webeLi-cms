import { test, expect, type Page } from '@playwright/test';

const baseUrl = process.env.E2E_BASE_URL;
const email = process.env.E2E_ADMIN_EMAIL;
const password = process.env.E2E_ADMIN_PASSWORD;
const hasDedicatedEnvironment = Boolean(baseUrl && email && password);

function cmsPath(path: string): string {
  if (!baseUrl) return path;
  const configured = new URL(baseUrl);
  const prefix = configured.pathname.replace(/\/+$/, '');
  return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g, '/');
}

async function signIn(page: Page): Promise<void> {
  await page.goto(cmsPath('/admin/login'));
  await page.getByLabel('Email').fill(email!);
  await page.getByRole('button', { name: /continuer/i }).click();
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: /se connecter/i }).click();
  await expect(page).toHaveURL(/\/admin\/app(?:\/|$)/);
}

test.describe('admin i18n FR/EN', () => {
  test.skip(!hasDedicatedEnvironment, 'Dedicated E2E_BASE_URL, E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD are required');

  test('keeps admin and Sale navigation localized on desktop and mobile', async ({ page }) => {
    test.setTimeout(60_000);
    await signIn(page);
    await page.goto(cmsPath('/admin/app/sale'));
    await expect(page.getByRole('heading', { name: /^Ventes/, level: 1 })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Commandes' })).toBeVisible();

    await page.evaluate(() => localStorage.setItem('amcms.admin.uiLanguage', 'en'));
    await page.reload();
    await expect(page.getByRole('heading', { name: /^Sales/, level: 1 })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Orders' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'To process' })).toBeVisible();
    await expect(page.locator('body')).not.toContainText('À traiter');

    await page.goto(cmsPath('/admin/app/business/relations'));
    await expect(page.getByRole('button', { name: 'All', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Customers', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Suppliers', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Follow up', exact: true })).toBeVisible();
    await page.setViewportSize({ width: 390, height: 820 });
    await page.getByRole('button', { name: 'Open menu' }).click();
    await expect(page.locator('#adminMobileNavigation')).toContainText('Modules');
    await expect(page.locator('#adminMobileNavigation')).toContainText('Sales');
  });
});
