import { test, expect, type Page } from '@playwright/test';

const configuredBaseUrl = process.env.E2E_BASE_URL;
const email = process.env.E2E_ADMIN_EMAIL;
const password = process.env.E2E_ADMIN_PASSWORD;
const enabled = Boolean(configuredBaseUrl && email && password);

function cmsPath(path: string): string {
  if (!configuredBaseUrl) return path;
  const prefix = new URL(configuredBaseUrl).pathname.replace(/\/+$/, '');
  return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g, '/');
}

async function orderableSellable(page: Page): Promise<{ id: number; slug: string }> {
  await page.goto(cmsPath('/admin/login'));
  await page.getByLabel('Email').fill(email!);
  await page.getByRole('button', { name: /continuer/i }).click();
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: /se connecter/i }).click();
  const contextResponse = await page.request.get(cmsPath('/admin/api/context'));
  expect(contextResponse.ok(), await contextResponse.text()).toBeTruthy();
  const context = (await contextResponse.json()).data;
  const rebuild = await page.request.post(cmsPath('/admin/api/business/pim/storefront-projections/rebuild'), {
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': context.csrf_token, 'X-Contract-Version': 'admin-api-v1' },
    data: { data: { locale: 'fr' } },
  });
  expect(rebuild.ok(), await rebuild.text()).toBeTruthy();
  const response = await page.request.get(cmsPath('/api/v1/storefront/products?lang=fr&limit=100'));
  expect(response.ok(), await response.text()).toBeTruthy();
  const items = (await response.json()).data.items as any[];
  const product = items.find((item) => item.type === 'physical' && Number(item.default_sellable_id || item.cta?.sellable_id) > 0);
  expect(product).toBeTruthy();
  return { id: Number(product.default_sellable_id || product.cta.sellable_id), slug: String(product.slug) };
}

async function createCart(page: Page, sellableId: number): Promise<{ token: string; version: number }> {
  const created = await page.request.post(cmsPath('/api/v1/sale/channels/web-main/cart?lang=fr'), { data: { data: {} } });
  expect(created.status(), await created.text()).toBe(201);
  const cart = (await created.json()).data.cart;
  const line = await page.request.post(cmsPath(`/api/v1/sale/channels/web-main/cart/${encodeURIComponent(cart.token)}/lines?lang=fr`), {
    headers: { 'Idempotency-Key': `point44-line-${Date.now()}` },
    data: { data: { sellable_id: sellableId, quantity: 1, expected_version: cart.version } },
  });
  expect(line.status(), await line.text()).toBe(201);
  return { token: String(cart.token), version: Number((await line.json()).data.cart.version) };
}

test.describe('44 panier, tiroir et checkout résilients', () => {
  test.skip(!enabled, 'E2E_BASE_URL is required');

  test('keeps the accessible drawer recoverable across a two-tab version conflict', async ({ page }) => {
    // Projection rebuild + the serialized PHP E2E server can consume most of
    // Playwright's 30 s default before the actual two-tab conflict is sent.
    test.setTimeout(90_000);
    const product = await orderableSellable(page);
    await page.goto(cmsPath(`/shop/products/${product.slug}?lang=fr`));
    await page.evaluate(() => localStorage.removeItem('amcms.cart.web-main'));
    await page.reload();
    const add = page.locator(`[data-storefront-add-to-cart][data-sellable-id="${product.id}"]`).first();
    await add.focus();
    await add.press('Enter');
    const drawer = page.locator('[data-cart-drawer]');
    await expect(drawer).toBeVisible();
    await expect(drawer).toHaveAttribute('role', 'dialog');
    await expect(drawer).toHaveAttribute('aria-modal', 'true');
    const token = await page.evaluate(() => localStorage.getItem('amcms.cart.web-main') || '');
    expect(token.length).toBeGreaterThan(31);
    const shown = await page.request.get(cmsPath(`/api/v1/sale/channels/web-main/cart/${encodeURIComponent(token)}?lang=fr`));
    const current = (await shown.json()).data.cart;
    const lineId = Number(current.lines[0].id);
    const otherTab = await page.request.patch(cmsPath(`/api/v1/sale/channels/web-main/cart/${encodeURIComponent(token)}/lines/${lineId}?lang=fr`), {
      data: { data: { quantity: 2, expected_version: current.version } },
    });
    expect(otherTab.ok(), await otherTab.text()).toBeTruthy();
    await drawer.locator('[data-cart-quantity]').fill('3');
    await drawer.locator('[data-cart-quantity]').dispatchEvent('change');
    await expect(drawer.locator('[data-cart-error]')).toContainText(/autre onglet|version la plus récente/i);
    await expect(drawer.locator('[data-cart-quantity]')).toHaveValue('2');
    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await expect(add).toBeFocused();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator('[data-cart-toggle]').click();
    expect(await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth)).toBe(false);
  });

  test('restores a guest draft after refresh and prevents a double checkout submission', async ({ page }) => {
    const product = await orderableSellable(page);
    const cart = await createCart(page, product.id);
    await page.goto(cmsPath(`/checkout?channel=web-main&cart_token=${encodeURIComponent(cart.token)}&lang=fr`));
    await expect(page.locator('.checkout-progress li')).toHaveCount(5);
    await page.getByLabel('Prénom').fill('Reprise');
    await page.getByLabel('Nom', { exact: true }).fill('Point 44');
    await page.getByLabel('E-mail').fill(`point44-${Date.now()}@example.test`);
    await page.getByLabel('Adresse', { exact: true }).fill('Rue de la reprise 44');
    await page.getByLabel('Code postal').fill('1000');
    await page.getByLabel('Ville').fill('Lausanne');
    await page.reload();
    await expect(page.getByLabel('Prénom')).toHaveValue('Reprise');
    await expect(page.getByLabel('Adresse', { exact: true })).toHaveValue('Rue de la reprise 44');
    await page.locator('select[name="shipping_method"]').selectOption('standard');
    await page.getByLabel('J’accepte les conditions générales de vente').check();
    let checkoutRequests = 0;
    page.on('request', (request) => {
      if (request.method() === 'POST' && request.url().includes('/api/v1/sale/channels/web-main/checkout')) checkoutRequests += 1;
    });
    const submit = page.getByRole('button', { name: 'Commander' });
    const responsePromise = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes('/api/v1/sale/channels/web-main/checkout'));
    await submit.click();
    await submit.evaluate((button: HTMLButtonElement) => button.click());
    expect((await responsePromise).status()).toBe(201);
    await expect(page.locator('[data-checkout-summary]')).toContainText('Commande SALE-');
    expect(checkoutRequests).toBe(1);
  });

  test('does not expose cart or Sale bootstrap outside an active Shop language', async ({ page }) => {
    const cartPage = await page.goto(cmsPath('/cart?lang=zz'));
    expect(cartPage?.status()).toBe(404);
    await expect(page.getByRole('heading', { name: 'Panier indisponible' })).toBeVisible();
    const bootstrap = await page.request.get(cmsPath('/api/v1/sale/channels/web-main/bootstrap?lang=zz'));
    expect(bootstrap.status()).toBe(404);
  });
});
