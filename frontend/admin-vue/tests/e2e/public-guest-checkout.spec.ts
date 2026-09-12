import { test, expect, type Page } from '@playwright/test';

const configuredBaseUrl = process.env.E2E_BASE_URL;
const enabled = Boolean(configuredBaseUrl);

function cmsPath(path: string): string {
  if (!configuredBaseUrl) return path;
  const configured = new URL(configuredBaseUrl);
  const prefix = configured.pathname.replace(/\/+$/, '');
  return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g, '/');
}

async function createCheckoutCart(page: Page): Promise<string> {
  const variantsResponse = await page.request.get(cmsPath('/api/v1/catalog/products?channel=ecommerce&limit=100'));
  const variantsText = await variantsResponse.text();
  expect(variantsResponse.ok(), variantsText).toBeTruthy();
  const variants = JSON.parse(variantsText);
  const catalogVariants = (variants.data?.items || []).flatMap((product: any) => product.variants || []);
  const orderableVariant = catalogVariants.find((variant: any) =>
    variant.is_sellable_public === true && variant.availability?.is_orderable === true
  );
  const variantId = Number(orderableVariant?.id ?? 0);
  expect(variantId).toBeGreaterThan(0);

  const cartResponse = await page.request.post(cmsPath('/api/v1/sale/channels/web-main/cart'), { data: { data: {} } });
  expect(cartResponse.status()).toBe(201);
  const cart = await cartResponse.json();
  const token = String(cart.data.cart.token);
  const lineResponse = await page.request.post(cmsPath(`/api/v1/sale/channels/web-main/cart/${encodeURIComponent(token)}/lines`), {
    headers: { 'Idempotency-Key': `e2e-line-${Date.now()}` },
    data: { data: { sellable_id: variantId, quantity: 1 } },
  });
  expect(lineResponse.status()).toBe(201);
  return token;
}

async function completeGuestCheckout(page: Page, viewport: { width: number; height: number }): Promise<void> {
  await page.setViewportSize(viewport);
  const token = await createCheckoutCart(page);
  await page.goto(cmsPath(`/checkout?channel=web-main&cart_token=${encodeURIComponent(token)}&lang=fr`));
  await expect(page.getByRole('heading', { name: 'Commande invitée' })).toBeVisible();
  await page.getByLabel('Prénom').fill('Anne');
  await page.getByLabel('Nom', { exact: true }).fill('Mobile');
  await page.getByLabel('E-mail').fill(`guest-${Date.now()}@example.test`);
  await page.getByLabel('Adresse', { exact: true }).fill('Rue du Test 1');
  await page.getByLabel('Code postal').fill('1000');
  await page.getByLabel('Ville').fill('Lausanne');
  await expect(page.locator('select[name="shipping_method"] option[value="standard"]')).toHaveCount(1);
  await page.locator('select[name="shipping_method"]').selectOption('standard');
  await page.getByLabel('J’accepte les conditions générales de vente').check();
  await page.getByLabel('J’accepte de recevoir des communications marketing (facultatif)').uncheck();
  await page.getByRole('button', { name: 'Commander' }).click();
  await expect(page.locator('[data-checkout-summary]')).toContainText('Commande SALE-');
  await expect(page.locator('[data-checkout-form]')).toBeHidden();
}

test.describe('public guest checkout SSR', () => {
  test.skip(!enabled, 'E2E_BASE_URL is required');

  test('completes a guest order on desktop', async ({ page }) => {
    await completeGuestCheckout(page, { width: 1280, height: 900 });
  });

  test('completes a guest order on mobile', async ({ page }) => {
    await completeGuestCheckout(page, { width: 390, height: 844 });
  });
});
