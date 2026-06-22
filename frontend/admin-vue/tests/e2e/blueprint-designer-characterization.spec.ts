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

test.describe('blueprint designer current behavior', () => {
  test.skip(!hasDedicatedEnvironment, 'Dedicated E2E_BASE_URL, E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD are required');

  test.beforeEach(async ({ page }) => {
    await signIn(page);
    await page.goto(cmsPath('/admin/app/blueprints'));
    await expect(page.getByRole('heading', { name: 'Blueprints & fieldsets' })).toBeVisible();
  });

  test('routes through the configured CMS prefix', async ({ page }) => {
    const expectedPrefix = new URL(baseUrl!).pathname.replace(/\/+$/, '');
    expect(page.url()).toContain(`${expectedPrefix}/admin/app/blueprints`);
  });

  test('unsaved general edits are discarded when selecting another blueprint', async ({ page }) => {
    const models = page.locator('.blueprint-column--models .list-group-item-action');
    test.skip(await models.count() < 2, 'At least two blueprint fixtures are required');

    const first = models.first();
    await first.click();
    const label = page.getByLabel('Label', { exact: true }).first();
    const original = await label.inputValue();
    await label.fill(`${original} — modification locale E2E`);

    // CURRENT BEHAVIOR: general v-model edits do not schedule autosave. Loading
    // another model and returning silently restores the persisted value.
    await models.nth(1).click();
    await models.first().click();
    await expect(label).toHaveValue(original);
  });
});
