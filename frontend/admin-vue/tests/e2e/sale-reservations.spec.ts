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

async function mockReservations(page: Page) {
  let released: Record<string, unknown>|null = null;
  let savedPolicy: Record<string, unknown>|null = null;
  await page.route('**/admin/api/sale/stock/reservation**', async (route) => {
    const request = route.request(); const url = new URL(request.url());
    if (url.pathname.endsWith('/reservations') && request.method() === 'GET') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        reservations: [
          { id: 1, reservation_kind: 'physical', status: 'active', quantity: 1, product_name: 'Dernier sac', sku: 'LAST-ONE', location_name: 'Stock principal', cart_id: 41, expires_at: '2099-01-01 00:05:00', ttl_seconds: 300, expiring_soon: 1, blocked: 0, releasable: true, fulfillment_mode: 'delivery', reservation_trigger: 'checkout_start' },
          { id: 2, reservation_kind: 'backorder', status: 'confirmed', quantity: 2, product_name: 'Veste sur commande', sku: 'BACK-2', location_name: 'Stock principal', order_id: 72, order_number: 'SALE-72', expires_at: '2099-01-01 01:00:00', ttl_seconds: 3600, expiring_soon: 0, blocked: 0, releasable: true, fulfillment_mode: 'delivery', reservation_trigger: 'order_placement', delivery_lead_time_days: 8 },
          { id: 3, reservation_kind: 'physical', status: 'active', quantity: 1, product_name: 'Réservation bloquée', sku: 'BLOCKED', location_name: 'Retrait Lausanne', cart_id: 43, expires_at: '2020-01-01 00:00:00', ttl_seconds: -10, expiring_soon: 1, blocked: 1, releasable: true, fulfillment_mode: 'pickup', reservation_trigger: 'checkout_start' },
        ],
        summary: { active: 3, expiring_soon: 2, blocked: 1, order_linked: 1, backorders: 1 },
        policies: [{ channel_id: 3, channel_name: 'Boutique web principale', channel_code: 'web-main', channel_kind: 'storefront', reservation_policy: 'checkout_start', reservation_ttl_seconds: 1800, reservation_renewal_window_seconds: 300, reservation_max_lifetime_seconds: 7200, backorder_policy: 'sellable', show_exact_quantity: 0 }],
      } }) }); return;
    }
    if (url.pathname.endsWith('/release')) { released = request.postDataJSON(); await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { reservation: { id: 1, status: 'released' } } }) }); return; }
    if (url.pathname.includes('/reservation-policies/') && request.method() === 'PUT') { savedPolicy = request.postDataJSON(); await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { policy: savedPolicy } }) }); return; }
    if (url.pathname.endsWith('/expire')) { await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { expired: 1 } }) }); return; }
    if (url.pathname.endsWith('/renew')) { await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { reservation: { id: 1, _renewed: true } } }) }); return; }
    await route.fallback();
  });
  return { released: () => released, savedPolicy: () => savedPolicy };
}

test.describe('M6 reservations, expiry and backorder UX', () => {
  test.skip(!enabled, 'Dedicated E2E admin environment is required');

  test('shows decision states, human TTL, order link and mobile layout', async ({ page }) => {
    await signIn(page); await mockReservations(page); await page.goto(cmsPath('/admin/app/sale/reservations'));
    await expect(page.getByRole('heading', { name: 'Réservations et backorders' })).toBeVisible();
    await expect(page.getByText('5 min restantes')).toBeVisible();
    await expect(page.getByText('Backorder explicite · 2', { exact: true })).toBeVisible();
    await expect(page.getByText('SALE-72')).toBeVisible();
    await expect(page.locator('.reservation-row.blocked').getByText('Expirée', { exact: true })).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByText('Dernier sac')).toBeVisible();
    await expect(page.getByText('Réservation bloquée')).toBeVisible();
  });

  test('requires a release reason and configures the channel policy', async ({ page }) => {
    await signIn(page); const state = await mockReservations(page); await page.goto(cmsPath('/admin/app/sale/reservations'));
    await page.getByRole('button', { name: 'Libérer' }).first().click();
    await expect(page.getByRole('button', { name: 'Confirmer la libération' })).toBeDisabled();
    await page.getByLabel('Raison obligatoire').fill('Client parti avant paiement');
    await page.getByRole('button', { name: 'Confirmer la libération' }).click();
    await expect.poll(() => state.released()).not.toBeNull();
    expect(state.released()).toMatchObject({ data: { reservation_kind: 'physical', reason: 'Client parti avant paiement' } });
    await page.getByLabel('Déclenchement').selectOption('payment_authorization');
    await page.getByLabel('TTL (s)').fill('900');
    await page.getByRole('button', { name: 'Enregistrer' }).click();
    await expect.poll(() => state.savedPolicy()).not.toBeNull();
    expect(state.savedPolicy()).toMatchObject({ data: { reservation_policy: 'payment_authorization', reservation_ttl_seconds: 900 } });
  });
});
