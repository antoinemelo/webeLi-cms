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
  await page.getByRole('button', { name: /continuer/i }).click();
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: /se connecter/i }).click();
  await expect(page).toHaveURL(/\/admin\/app(?:\/|$)/);
}

test.describe('38a architecture des modules et navigation', () => {
  test.skip(!enabled, 'Dedicated E2E admin environment is required');

  test('exposes task navigation, canonical redirects and a mobile selector', async ({ page }) => {
    test.setTimeout(180_000);
    await signIn(page);
    await page.goto(cmsPath('/admin/app/sale'));
    const nav = page.locator('.module-secondary-navigation').first();
    await expect(nav.getByRole('link', { name: 'À traiter' })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Commandes' })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Paiements' })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Réservations de stock' })).toHaveCount(0);
    await expect(nav.getByRole('link', { name: 'Exécution logistique' })).toHaveCount(0);
    await expect(nav.getByRole('link', { name: 'Rapprochement des identités' })).toHaveCount(0);

    await nav.getByRole('link', { name: 'À traiter' }).focus();
    await page.keyboard.press('Tab');
    await expect(nav.getByRole('link', { name: 'Commandes' })).toBeFocused();

    await page.goto(cmsPath('/admin/app/sale/settings'));
    await expect(nav.getByRole('link', { name: 'Réglages' })).toHaveAttribute('aria-current', 'page');
    await expect(nav.getByRole('link', { name: 'À traiter' })).not.toHaveAttribute('aria-current', 'page');

    await page.setViewportSize({ width: 390, height: 820 });
    const mobileSelect = nav.locator('select');
    await expect(mobileSelect).toBeVisible();
    await mobileSelect.selectOption('/sale/orders');
    await expect(page).toHaveURL(/\/admin\/app\/sale\/orders$/);

    await page.goto(cmsPath('/admin/app/sale/stock'), { timeout: 60_000 });
    await expect(page).toHaveURL(/\/admin\/app\/sale\/advanced\/stock$/);
    await page.goto(cmsPath('/admin/app/business/catalog'), { timeout: 60_000 });
    await expect(page).toHaveURL(/\/admin\/app\/business\/products-stock$/);
  });

  test('opens relations, orders and products from permission-aware global search', async ({ page }) => {
    await signIn(page);
    await page.route('**/admin/api/entries**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) }));
    await page.route('**/admin/api/business/search**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { relations: [{ id: 17, type: 'contact', title: 'Ada Exemple', subtitle: 'ada@example.test' }] } }) }));
    await page.route('**/admin/api/sale/orders**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { orders: [{ id: 27, order_number: 'WEB-0027', status: 'placed' }] } }) }));
    await page.route('**/admin/api/business/catalog/products**', (route) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { products: [{ id: 37, name: 'Gourde exemple', sku_base: 'GOURDE' }] } }) }));

    await page.goto(cmsPath('/admin/app'));
    const search = page.locator('.global-search-form input');
    await search.fill('exemple');
    await expect(page.getByText('Ada Exemple')).toBeVisible();
    await expect(page.getByText('WEB-0027')).toBeVisible();
    await expect(page.getByText('Gourde exemple')).toBeVisible();
    await page.getByText('Ada Exemple').click();
    await expect(page).toHaveURL(/\/business\/relations\?relation_type=contact&relation_id=17$/);
  });

  test('moves E-Commerce from the module catalog to Sale settings', async ({ page }) => {
    await signIn(page);
    await page.goto(cmsPath('/admin/app/modules/commerce'));
    await expect(page).toHaveURL(/\/admin\/app\/sale\/settings\?section=ecommerce$/);
    await expect(page.getByRole('button', { name: 'E-Commerce', exact: true })).toHaveAttribute('aria-current', 'page');
    await expect(page.getByRole('heading', { name: /^E-Commerce/ })).toBeVisible();

    await page.goto(cmsPath('/admin/app/modules'));
    await expect(page.getByText('commerce · v0.1.0')).toHaveCount(0);
  });
});
