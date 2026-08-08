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

async function signIn(page: Page): Promise<string> {
  await page.goto(cmsPath('/admin/login'));
  await page.getByLabel('Email').fill(email!);
  await page.getByRole('button', { name: /continuer/i }).click();
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: /se connecter/i }).click();
  await expect(page).toHaveURL(/\/admin\/app(?:\/|$)/);
  const context = await page.request.get(cmsPath('/admin/api/context'));
  expect(context.ok(), await context.text()).toBeTruthy();
  return String((await context.json()).data.csrf_token);
}

const headers = (csrf: string): Record<string, string> => ({
  'Content-Type': 'application/json',
  'X-Contract-Version': 'admin-api-v1',
  'X-CSRF-Token': csrf,
});

async function selectContentLanguage(page: Page, languageCode: string): Promise<void> {
  const label = languageCode.slice(0, 2).toUpperCase();
  const languageButton = page.getByRole('button', { name: new RegExp(`^${label}(?:\\s|$)`) });
  const directButton = languageButton.filter({ hasText: new RegExp(`^${label}$`) });
  if (await directButton.count()) {
    await directButton.click();
    return;
  }
  await page.getByRole('button', { name: '⋯', exact: true }).click();
  await languageButton.click();
}

test.describe('39 Shop système, activation multisite et Studio', () => {
  test.skip(!enabled, 'Dedicated E2E admin environment is required');

  test('keeps Studio publication separate and gates route, menu, cart and headless by locale', async ({ page }) => {
    test.setTimeout(240_000);
    const anonymous = await page.request.get(cmsPath('/admin/api/sale/ecommerce/shops'));
    expect(anonymous.status()).toBe(401);

    const csrf = await signIn(page);
    const matrixResponse = await page.request.get(cmsPath('/admin/api/sale/ecommerce/shops'));
    expect(matrixResponse.ok(), await matrixResponse.text()).toBeTruthy();
    const matrix = (await matrixResponse.json()).data.shops as Array<Record<string, unknown>>;
    const french = matrix.find(shop => Number(shop.site_id) === 1 && shop.language_code === 'fr');
    const uninitialized = matrix.find(shop => Number(shop.site_id) === 1 && !Boolean(shop.is_initialized));
    expect(french).toBeTruthy();
    expect(french?.status).toBe('active');
    expect(uninitialized).toBeTruthy();

    await page.goto(cmsPath('/admin/app/contents/pages/system-shop?site_id=1&language_code=fr'));
    await expect(page.getByRole('heading', { name: 'Page système Boutique' })).toBeVisible();
    await expect(page.getByText('Système — Boutique', { exact: true })).toBeVisible();
    await expect(page.getByText('/shop', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Aperçu du brouillon' }).click();
    await expect(page.getByLabel('Aperçu Studio non public')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Publier la configuration' })).toBeVisible();

    await page.goto(cmsPath('/admin/app/contents/pages'));
    await expect(page.getByText('Système', { exact: true })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByRole('row', { name: /Boutique.*Système/ }).getByText('Actif', { exact: true })).toBeVisible();
    const configuredBasePath = `/configured-${Date.now().toString(36)}`;
    await page.evaluate(({ basePath, apiBasePath }) => {
      window.__AMCMS_ADMIN__ = { ...(window.__AMCMS_ADMIN__ || {}), basePath, apiBasePath };
    }, { basePath: configuredBasePath, apiBasePath: cmsPath('/admin/api') });
    const uninitializedShopMatrix = page.waitForResponse(
      (response) => response.request().method() === 'GET' && /\/admin\/api\/sale\/ecommerce\/shops(?:\?|$)/.test(response.url()),
      { timeout: 60_000 },
    );
    await selectContentLanguage(page, String(uninitialized?.language_code));
    expect((await uninitializedShopMatrix).ok()).toBeTruthy();
    await expect(page.getByText('Système', { exact: true })).toHaveCount(0);
    const frenchShopMatrix = page.waitForResponse(
      (response) => response.request().method() === 'GET' && /\/admin\/api\/sale\/ecommerce\/shops(?:\?|$)/.test(response.url()),
      { timeout: 60_000 },
    );
    await selectContentLanguage(page, 'fr');
    expect((await frenchShopMatrix).ok()).toBeTruthy();
    await expect(page.getByText('Système', { exact: true })).toBeVisible();
    const shopRow = page.getByRole('row', { name: /Boutique.*Système/ });
    const shopButton = shopRow.getByRole('button', { name: '/shop', exact: true });
    await expect(shopButton).toBeVisible();
    await expect(shopButton).toHaveAttribute('title', `${configuredBasePath}/shop`);
    const popupPromise = page.waitForEvent('popup');
    await shopButton.click();
    const popup = await popupPromise;
    await expect.poll(() => new URL(popup.url()).pathname).toBe(`${configuredBasePath}/shop`);
    await popup.close();

    const activeShop = await page.request.get(cmsPath('/shop?lang=fr'));
    expect(activeShop.status()).toBe(200);
    await page.goto(cmsPath('/'));
    const publicBrand = page.locator('a.navbar-brand.brand').first();
    const stableHomePath = cmsPath('/').replace(/\/$/, '') || '/';
    await expect(publicBrand).toHaveAttribute('href', stableHomePath);
    await publicBrand.click();
    expect(new URL(page.url()).origin).toBe(new URL(baseUrl!).origin);
    expect(new URL(page.url()).pathname).toBe(stableHomePath);
    const deactivateEnglish = await page.request.post(cmsPath('/admin/api/sale/ecommerce/shops/1/en/deactivate'), {
      headers: headers(csrf), data: {},
    });
    expect(deactivateEnglish.ok(), await deactivateEnglish.text()).toBeTruthy();
    try {
      const inactiveEnglishShop = await page.request.get(cmsPath('/en/shop?lang=en'));
      expect(inactiveEnglishShop.status()).toBe(404);
    } finally {
      const restoreEnglish = await page.request.post(cmsPath('/admin/api/sale/ecommerce/shops/1/en/activate'), {
        headers: headers(csrf), data: {},
      });
      expect(restoreEnglish.ok(), await restoreEnglish.text()).toBeTruthy();
    }

    let restored = false;
    try {
      const deactivate = await page.request.post(cmsPath('/admin/api/sale/ecommerce/shops/1/fr/deactivate'), {
        headers: headers(csrf), data: {},
      });
      expect(deactivate.ok(), await deactivate.text()).toBeTruthy();
      expect((await deactivate.json()).data.status).toBe('inactive');

      expect((await page.request.get(cmsPath('/shop?lang=fr'))).status()).toBe(404);
      expect((await page.request.get(cmsPath('/api/v1/storefront/products?lang=fr'))).status()).toBe(404);
      expect((await page.request.get(cmsPath('/api/v1/storefront/collections?lang=fr'))).status()).toBe(404);
      await page.goto(cmsPath('/'));
      await expect(page.locator('.system-shop-menu-item')).toHaveCount(0);
      await expect(page.locator('[data-cart-toggle]')).toHaveCount(0);

      await page.goto(cmsPath('/admin/app/contents/pages'));
      const inactiveShopRow = page.getByRole('row', { name: /Boutique.*Système/ });
      await expect(inactiveShopRow.getByText('Inactif', { exact: true })).toBeVisible();
      await expect(inactiveShopRow.getByText('/shop', { exact: true })).toHaveCount(0);

      const activate = await page.request.post(cmsPath('/admin/api/sale/ecommerce/shops/1/fr/activate'), {
        headers: headers(csrf), data: {},
      });
      expect(activate.ok(), await activate.text()).toBeTruthy();
      expect((await activate.json()).data.status).toBe('active');
      restored = true;

      expect((await page.request.get(cmsPath('/shop?lang=fr'))).status()).toBe(200);
      await page.goto(cmsPath('/'));
      await expect(page.locator('.system-shop-menu-item')).toHaveCount(1);
      await expect(page.locator('[data-cart-toggle]')).toBeVisible();

      const replay = await page.request.post(cmsPath('/admin/api/sale/ecommerce/shops/1/fr/activate'), {
        headers: headers(csrf), data: {},
      });
      expect(replay.ok(), await replay.text()).toBeTruthy();
      expect((await replay.json()).data.status).toBe('active');
    } finally {
      if (!restored) {
        await page.request.post(cmsPath('/admin/api/sale/ecommerce/shops/1/fr/activate'), {
          headers: headers(csrf), data: {},
        });
      }
    }

    await page.goto(cmsPath('/admin/app/modules/commerce'));
    await expect(page).toHaveURL(/\/admin\/app\/sale\/settings\?section=ecommerce$/);
    await page.goto(cmsPath('/admin/app/modules'));
    await expect(page.getByText('commerce · v0.1.0')).toHaveCount(0);

    await page.setViewportSize({ width: 390, height: 820 });
    await page.goto(cmsPath('/admin/app/sale/settings?section=ecommerce'));
    await expect(page.getByRole('heading', { name: /^E-Commerce/ })).toBeVisible();
    const studioLink = page.getByRole('link', { name: 'Ouvrir Studio' }).first();
    await studioLink.focus();
    await expect(studioLink).toBeFocused();

    await page.evaluate(() => localStorage.setItem('amcms.admin.uiLanguage', 'en'));
    await page.goto(cmsPath('/admin/app/contents/pages/system-shop?site_id=1&language_code=fr'));
    await expect(page.getByRole('heading', { name: 'Shop system page' })).toBeVisible();
    await expect(page.getByText('System — Shop', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Preview draft' })).toBeVisible();
    await page.goto(cmsPath('/admin/app/contents/pages'));
    await expect(page.getByText('System', { exact: true })).toBeVisible();
  });
});
