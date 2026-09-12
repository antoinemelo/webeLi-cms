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

function stockItem(index: number) {
  return {
    id: index, sellable_id: index, stock_location_id: 1, sku: `SCAN-${String(index).padStart(4, '0')}`,
    barcode: `761000${String(index).padStart(4, '0')}`, product_name: `Produit ${index}`,
    variant_name: 'Standard', location_name: 'Stock principal', location_code: 'channel-default',
    on_hand_quantity: index === 1 ? 1 : 12, reserved_quantity: index === 1 ? 0 : 2,
    available_quantity: index === 1 ? 1 : 10, low_stock_threshold: 2,
    availability_status: 'in_stock', last_available: index === 1, low_stock: index === 1, inconsistent: false,
  };
}

async function mockStock(page: Page, options: { forbidden?: boolean; empty?: boolean } = {}) {
  let lastItemsUrl = '';
  let adjustment: Record<string, unknown> | null = null;
  await page.route('**/admin/api/sale/stock/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (options.forbidden) {
      await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ error: { code: 'FORBIDDEN', message: 'Permission sale.stock.read requise.' } }) });
      return;
    }
    if (url.pathname.endsWith('/items')) {
      lastItemsUrl = request.url();
      const items = options.empty ? [] : Array.from({ length: 100 }, (_, index) => stockItem(index + 1));
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        items, locations: [{ id: 1, code: 'channel-default', name: 'Stock principal', location_type: 'main' }],
        summary: { item_count: items.length, on_hand_quantity: 1189, reserved_quantity: 198, available_quantity: 991, out_of_stock_count: 0, low_stock_count: 1 },
      } }) });
      return;
    }
    if (url.pathname.endsWith('/movements')) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { movements: [{ id: 1, movement_type: 'initial', quantity: 1, reason: 'Seed contrôlé', reference_type: 'catalog_seed', reference_id: 1, created_at: '2026-07-14 08:00:00' }] } }) });
      return;
    }
    if (url.pathname.endsWith('/adjustments') && request.method() === 'POST') {
      adjustment = request.postDataJSON();
      await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: { item: stockItem(1) } }) });
      return;
    }
    await route.fallback();
  });
  return { lastItemsUrl: () => lastItemsUrl, adjustment: () => adjustment };
}

test.describe('M6 ledger stock Sale', () => {
  test.skip(!enabled, 'Dedicated E2E admin environment is required');
  test.setTimeout(90_000);

  test('supports large lists, scanner search, persistent filters and mobile consultation', async ({ page }) => {
    test.setTimeout(90_000);
    await signIn(page);
    const state = await mockStock(page);
    await page.goto(cmsPath('/admin/app/sale/advanced/stock'));
    await expect(page.getByRole('heading', { name: 'Stock transactionnel' })).toBeVisible();
    await expect(page.locator('.stock-row')).toHaveCount(100);
    await page.getByPlaceholder('Nom, SKU, code-barres ou emplacement').fill('7610000001');
    await page.getByRole('button', { name: 'Appliquer' }).click();
    await expect.poll(() => state.lastItemsUrl()).toContain('q=7610000001');
    await page.reload();
    await expect(page.getByPlaceholder('Nom, SKU, code-barres ou emplacement')).toHaveValue('7610000001');
    await page.locator('.stock-row').first().click();
    await expect(page.getByRole('heading', { name: 'Produit 1' })).toBeVisible();
    await expect(page.getByText('Dernier disponible')).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('.stock-detail')).toBeVisible();
    await expect(page.getByText('Ouverture', { exact: true })).toBeVisible();
  });

  test('rejects an invalid quantity and completes the five-step audited movement wizard', async ({ page }) => {
    await signIn(page);
    const state = await mockStock(page);
    await page.goto(cmsPath('/admin/app/sale/advanced/stock'));
    await page.locator('.stock-row').first().click();
    await page.getByRole('button', { name: 'Nouveau mouvement' }).last().click();
    await page.getByRole('button', { name: 'Suivant' }).click();
    await page.getByLabel('Type de mouvement').selectOption('issue');
    await page.getByLabel('Quantité signée').fill('2');
    await expect(page.getByText('Le mouvement produirait une quantité négative interdite.')).toBeVisible();
    await page.getByLabel('Quantité signée').fill('1');
    await page.getByRole('button', { name: 'Suivant' }).click();
    await page.getByLabel('Raison obligatoire').fill('Casse constatée lors de l’inventaire');
    await page.getByRole('button', { name: 'Suivant' }).click();
    await expect(page.getByText(/^Avant 1$/)).toBeVisible();
    await expect(page.getByText(/^Après 0$/)).toBeVisible();
    await page.getByRole('button', { name: 'Suivant' }).click();
    await page.getByRole('button', { name: 'Confirmer le mouvement' }).click();
    await expect.poll(() => state.adjustment()).not.toBeNull();
    expect(state.adjustment()).toMatchObject({ data: { movement_type: 'issue', quantity_delta: -1, reason: 'Casse constatée lors de l’inventaire' } });
    await expect(page.getByText('Mouvement créé et état recalculé.')).toBeVisible();
  });

  test('shows empty and permission states without a dead end', async ({ page }) => {
    await signIn(page);
    await mockStock(page, { empty: true });
    await page.goto(cmsPath('/admin/app/sale/advanced/stock'));
    await expect(page.getByText('Aucun item de stock ne correspond à ces filtres.')).toBeVisible();
    await page.unroute('**/admin/api/sale/stock/**');
    await mockStock(page, { forbidden: true });
    await page.reload();
    await expect(page.getByRole('alert')).toBeVisible();
  });
});
