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

test.describe('IAM roles permission matrix layout', () => {
  test.skip(!enabled, 'Dedicated E2E admin environment is required');

  test('uses nested content-height cards without internal matrix scrollbars', async ({ page }, testInfo) => {
    await page.setViewportSize({ width:1440, height:1000 });
    await signIn(page);
    await page.goto(cmsPath('/admin/app/iam/roles'));
    const matrix = page.locator('.permission-matrix');
    await expect(matrix).toBeVisible();
    expect(await matrix.locator('.permission-group').count()).toBeGreaterThan(4);
    expect(await matrix.locator('.permission-subgroup').count()).toBeGreaterThan(await matrix.locator('.permission-group').count());
    const desktop = await matrix.evaluate((element) => {
      const style = getComputedStyle(element);
      return { maxHeight:style.maxHeight, overflowY:style.overflowY, scrollHeight:element.scrollHeight, clientHeight:element.clientHeight, bodyWidth:document.body.scrollWidth, viewportWidth:document.documentElement.clientWidth };
    });
    expect(desktop.maxHeight).toBe('none');
    expect(desktop.overflowY).toBe('visible');
    expect(desktop.scrollHeight).toBeLessThanOrEqual(desktop.clientHeight + 1);
    expect(desktop.bodyWidth).toBeLessThanOrEqual(desktop.viewportWidth + 1);
    const domainColumns = await matrix.locator('.permission-group').evaluateAll((groups) => new Set(groups.slice(0, 10).map((group) => Math.round(group.getBoundingClientRect().x))).size);
    expect(domainColumns).toBeGreaterThan(1);
    await page.screenshot({ path:testInfo.outputPath('iam-roles-desktop.png'), fullPage:false });

    await page.setViewportSize({ width:390, height:820 });
    await expect(matrix).toBeVisible();
    const mobile = await page.evaluate(() => ({ bodyWidth:document.body.scrollWidth, viewportWidth:document.documentElement.clientWidth }));
    expect(mobile.bodyWidth).toBeLessThanOrEqual(mobile.viewportWidth + 1);
  });
});
