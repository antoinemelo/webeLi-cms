import { test, expect, type Page } from '@playwright/test';

const baseUrl = process.env.E2E_BASE_URL;
const email = process.env.E2E_ADMIN_EMAIL;
const password = process.env.E2E_ADMIN_PASSWORD;
const enabled = Boolean(baseUrl && email && password);

function cmsPath(path: string): string {
  if (!baseUrl) return path;
  const prefix = new URL(baseUrl).pathname.replace(/\/+$/, '');
  return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g, '/');
}

async function signIn(page: Page): Promise<void> {
  await page.goto(cmsPath('/admin/login'));
  await page.getByLabel('Email').fill(email!);
  await page.getByRole('button', { name:/continuer/i }).click();
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name:/se connecter/i }).click();
  await expect(page).toHaveURL(/\/admin\/app(?:\/|$)/);
}

async function expectUnifiedHandles(page: Page): Promise<void> {
  const handles = page.locator('.dnd-handle:visible');
  await expect(handles.first()).toBeVisible({ timeout:60_000 });
  expect(await handles.count()).toBeGreaterThan(0);
  for (const text of await handles.allTextContents()) expect(text.trim()).toBe('⋮');
  const styles = await handles.first().evaluate((element) => {
    const style = getComputedStyle(element);
    return {
      width:style.width,
      height:style.height,
      borderStyle:style.borderStyle,
      backgroundColor:style.backgroundColor,
    };
  });
  expect(styles).toEqual({
    width:'36px',
    height:'36px',
    borderStyle:'none',
    backgroundColor:'rgba(0, 0, 0, 0)',
  });
}

test.describe('Shared drag-and-drop handles', () => {
  test.skip(!enabled, 'Dedicated E2E admin environment is required');

  test('uses one vertical ellipsis in Taxonomies and the system Shop editor', async ({ page }) => {
    test.setTimeout(120_000);
    await signIn(page);

    await page.goto(cmsPath('/admin/app/taxonomies'));
    await expect(page.getByRole('heading', { name:'Taxonomies' })).toBeVisible();
    await expectUnifiedHandles(page);
    await expect(page.getByText('⠿', { exact:true })).toHaveCount(0);

    await page.goto(cmsPath('/admin/app/contents/pages/system-shop?site_id=1&language_code=fr'));
    await expectUnifiedHandles(page);
    await expect(page.getByText('⠿', { exact:true })).toHaveCount(0);
  });
});
