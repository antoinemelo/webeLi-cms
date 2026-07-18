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
function envelope(data: unknown): string { return JSON.stringify({ data }); }

test.describe('47 facturation, ventes et inventaire administrateur', () => {
  test.skip(!enabled, 'Dedicated E2E admin environment is required');

  test('exposes scoped policies, snapshot indicators and inventory operations without a second stock', async ({ page }) => {
    test.setTimeout(120_000);
    await signIn(page);

    const policyResponse = await page.request.get(cmsPath('/admin/api/sale/document-policy'), { headers: { 'X-Contract-Version': 'admin-api-v1' } });
    expect(policyResponse.ok()).toBeTruthy();
    const policy = (await policyResponse.json()).data.policy;
    expect(policy).toHaveProperty('invoice_trigger');
    expect(policy).toHaveProperty('seller_configured');

    const salesResponse = await page.request.get(cmsPath('/admin/api/sale/sales-dashboard?date_from=2026-01-01'), { headers: { 'X-Contract-Version': 'admin-api-v1' } });
    expect(salesResponse.ok()).toBeTruthy();
    const sales = (await salesResponse.json()).data.sales;
    expect(sales.scope.source).toBe('immutable_sale_order_snapshots');
    expect(sales.definitions).toHaveProperty('net_sales_minor');
    expect(sales.summary).toHaveProperty('gift_card_units');

    await page.route('**/admin/api/sale/dashboard**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ actionable: { tasks: [], groups: {}, total: 0 } }) }));
    await page.route('**/admin/api/sale/sales-dashboard**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ sales: {
      scope: { source: 'immutable_sale_order_snapshots' }, definitions: { ordered_minor: 'Total commandé', net_sales_minor: 'Après remboursements', paid_minor: 'Payé' },
      summary: { orders_count: 2, ordered_minor: 6700, net_sales_minor: 5700, paid_minor: 6700, refunded_minor: 1000, units_count: 3 },
      orders: [{ id: 47, order_number: 'SALE-47', placed_at: '2026-07-17 10:00:00', source: 'ecommerce', channel_name: 'Boutique', currency: 'CHF', grand_total_minor: 6700, payment_status: 'paid', fulfillment_status: 'fulfilled' }],
    } }) }));
    await page.goto(cmsPath('/admin/app/sale'));
    await expect(page.getByRole('heading', { name: 'Analyse des ventes' })).toBeVisible();
    await expect(page.getByText(/67[.,]00\s*CHF/).first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'SALE-47' })).toBeVisible();

    await page.route('**/admin/api/business/catalog/inventory**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({
      items: [{ id: 8, business_variant_id: 9, stock_location_id: 3, sku: 'SKU-47', barcode: '7610000000047', product_name: 'Produit 47', variant_name: 'Bleu', location_name: 'Principal', on_hand_quantity: 2, reserved_quantity: 2, available_quantity: 0, physical_purchase_value_minor: 2000, reserved_sale_value_minor: 5800, available_sale_value_minor: 0, currency: 'CHF', availability_status: 'out_of_stock', low_stock: false, inconsistent: false }],
      locations: [{ id: 3, name: 'Principal' }], summary: { item_count: 1, on_hand_quantity: 2, reserved_quantity: 2, available_quantity: 0, currency: 'CHF', physical_purchase_value_minor: 2000, reserved_sale_value_minor: 5800, available_sale_value_minor: 0 },
    }) }));
    await page.goto(cmsPath('/admin/app/business/inventory'));
    await expect(page.getByPlaceholder(/code-barres/)).toBeVisible();
    await expect(page.getByText('7610000000047')).toBeVisible();
    await page.getByRole('button', { name: 'Saisir une variation' }).click();
    const inventoryDialog = page.getByRole('dialog', { name: 'Variation de stock' });
    await expect(inventoryDialog.getByLabel('Opération')).toHaveValue('correction');
    await inventoryDialog.getByLabel('Opération').selectOption('receipt');
    await expect(inventoryDialog.getByLabel('Opération')).toHaveValue('receipt');
  });
});
