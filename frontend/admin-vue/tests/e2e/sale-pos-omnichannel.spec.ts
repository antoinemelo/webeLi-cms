import { test, expect, type APIResponse, type Page } from '@playwright/test';

const baseUrl = process.env.E2E_BASE_URL;
const email = process.env.E2E_ADMIN_EMAIL;
const password = process.env.E2E_ADMIN_PASSWORD;
const enabled = Boolean(baseUrl && email && password);
const cmsPath = (path: string): string => {
  if (!baseUrl) return path;
  const prefix = new URL(baseUrl).pathname.replace(/\/+$/, '');
  return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g, '/');
};
async function body(response: APIResponse, label: string): Promise<any> {
  const text = await response.text();
  expect(response.status(), `${label} returned 500: ${text}`).not.toBe(500);
  expect(response.ok(), `${label}: ${text}`).toBeTruthy();
  return JSON.parse(text);
}
async function signIn(page: Page): Promise<string> {
  await page.goto(cmsPath('/admin/login'));
  await page.getByLabel('Email').fill(email!);
  await page.getByRole('button', { name: /continuer/i }).click();
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: /se connecter/i }).click();
  await expect(page).toHaveURL(/\/admin\/app/);
  const context = await body(await page.request.get(cmsPath('/admin/api/context')), 'admin context');
  return String(context.data.csrf_token);
}
const headers = (csrf: string) => ({
  'Content-Type': 'application/json',
  'X-Contract-Version': 'admin-api-v1',
  'X-CSRF-Token': csrf,
  'Idempotency-Key': crypto.randomUUID(),
});

test.describe('M4 omnichannel POS', () => {
  test.skip(!enabled, 'Dedicated E2E environment and administrator credentials are required');
  test('opens an explicit register and adds a product from the POS interface', async ({ page }) => {
    test.setTimeout(120_000);
    await signIn(page);
    await page.goto(cmsPath('/admin/app/sale/pos'));

    await expect(page.getByLabel('Caisse', { exact: true })).toHaveValue(/\d+/);
    await expect(page.getByLabel('Fond de caisse')).toBeVisible();
    await expect(page.getByText('Montant en espèces déjà présent dans le tiroir au début de la session.')).toHaveCount(0);
    await page.getByLabel('Fond de caisse').fill('10.00');
    await page.getByRole('button', { name: 'Ouvrir', exact: true }).click();
    await expect(page.getByText('Session caisse ouverte.')).toBeVisible();

    const product = page.locator('.sale-pos__product').first();
    await expect(product).toBeEnabled();
    await product.click();
    await expect(page.locator('.sale-pos__line')).toHaveCount(1);
  });

  test('uses shared order, location stock, audited receipt and reconciled session', async ({ page }) => {
    test.setTimeout(120_000);
    const csrf = await signIn(page);
    let bootstrap = await body(await page.request.get(cmsPath('/admin/api/sale/pos/bootstrap'), { headers: headers(csrf) }), 'POS bootstrap');
    expect(bootstrap.data.offline_supported).toBe(false);

    let session = bootstrap.data.active_session;
    if (!session) {
      const opened = await body(await page.request.post(cmsPath('/admin/api/sale/pos/sessions/open'), {
        headers: headers(csrf), data: { data: { register_id: bootstrap.data.registers[0]?.id, opening_cash_minor: 1000 } },
      }), 'open POS session');
      session = opened.data.session;
      bootstrap = await body(await page.request.get(cmsPath('/admin/api/sale/pos/bootstrap'), { headers: headers(csrf) }), 'refreshed POS bootstrap');
    }
    expect(Number(session.channel_id)).toBeGreaterThan(0);
    expect(Number(session.stock_location_id)).toBeGreaterThan(0);
    expect(bootstrap.data.payment_methods.length).toBeGreaterThan(0);

    const catalog = await body(await page.request.get(cmsPath('/admin/api/sale/pos/variants?limit=1'), { headers: headers(csrf) }), 'POS catalog');
    const sellable = catalog.data.variants[0];
    expect(Number(sellable.sellable_id || sellable.business_variant_id)).toBeGreaterThan(0);
    const cart = await body(await page.request.post(cmsPath('/admin/api/sale/pos/carts'), {
      headers: headers(csrf), data: { data: { cash_session_id: session.id } },
    }), 'POS cart');
    const cartId = Number(cart.data.cart.id);
    await body(await page.request.post(cmsPath(`/admin/api/sale/pos/carts/${cartId}/lines`), {
      headers: headers(csrf), data: { data: { sellable_id: sellable.sellable_id || sellable.business_variant_id, quantity: 1 } },
    }), 'POS cart line');
    const checkout = await body(await page.request.post(cmsPath('/admin/api/sale/pos/checkout'), {
      headers: headers(csrf), data: { data: { cart_id: cartId, cash_session_id: session.id, payment_method: 'cash' } },
    }), 'POS checkout');
    const order = checkout.data.order;
    expect(order.source).toBe('pos');
    expect(Number(order.pos_session_id)).toBe(Number(session.id));
    expect(Number(order.stock_location_id)).toBe(Number(session.stock_location_id));
    expect(order.fulfillment_status).toBe('fulfilled');
    expect(order.status).toBe('completed');

    const dossier = await body(await page.request.get(cmsPath(`/admin/api/sale/orders/${order.id}/dossier`), { headers: headers(csrf) }), 'immediate POS order dossier');
    expect(dossier.data.dossier.fulfillment.operations).toHaveLength(0);
    const dashboard = await body(await page.request.get(cmsPath('/admin/api/sale/dashboard'), { headers: headers(csrf) }), 'sale actionable dashboard');
    expect(dashboard.data.actionable.tasks.some((task: any) => Number(task.order_id) === Number(order.id))).toBe(false);

    const reprint = await body(await page.request.post(cmsPath(`/admin/api/sale/pos/orders/${order.id}/receipt/reprint`), {
      headers: headers(csrf), data: { data: { reason: 'qualification M4 POS' } },
    }), 'audited POS receipt reprint');
    expect(reprint.data.receipt.printable_text).toContain('Ticket');

    const sessionReport = await body(await page.request.get(cmsPath('/admin/api/sale/reports/pos-sessions'), { headers: headers(csrf) }), 'POS reconciliation report');
    const reportRow = sessionReport.data.sessions.find((row: any) => Number(row.id) === Number(session.id));
    expect(Number(reportRow.orders_count)).toBeGreaterThan(0);
    expect(Number(reportRow.sales_minor)).toBeGreaterThan(0);
    expect(Number(reportRow.opening_cash_minor)).toBeGreaterThanOrEqual(0);
    expect(Number(reportRow.operational_movements_count)).toBeGreaterThan(0);
    expect(Number(reportRow.net_cash_movement_minor)).toBeGreaterThan(0);
  });

});
