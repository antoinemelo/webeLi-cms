import { test, expect, type BrowserContext, type Page } from '@playwright/test';

const configuredBaseUrl = process.env.E2E_BASE_URL;
const enabled = Boolean(configuredBaseUrl);
const token = 'gateproviderinterchangeability0001';

function cmsPath(path: string): string {
  if (!configuredBaseUrl) return path;
  const prefix = new URL(configuredBaseUrl).pathname.replace(/\/+$/, '');
  return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g, '/');
}

async function mockCheckout(page: Page, provider: string, language: 'fr' | 'en', failed = false): Promise<void> {
  await page.route('**/api/v1/sale/channels/web-main/**', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith(`/cart/${token}`)) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { cart: {
        token, currency: 'CHF', tax_total_minor: 100, shipping_total_minor: 0, grand_total_minor: 1200,
        lines: [{ quantity: 1, product_name: 'Gate product', line_total_minor: 1100 }],
      } } }) });
      return;
    }
    if (url.pathname.endsWith('/bootstrap')) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: {
        channel: { currency: 'CHF' },
        fulfillment_methods: [{ code: 'standard', label: language === 'en' ? 'Standard delivery' : 'Livraison standard', flat_rate_minor: 0 }],
        payment_methods: [{
          code: 'real_checkout', label: provider === 'stripe_checkout' ? 'Stripe Checkout' : 'Revolut Checkout',
          description: language === 'en' ? 'Secure hosted payment' : 'Paiement hébergé sécurisé', provider_key: provider,
          mode: 'redirect', next_action: 'redirect', recoverable: true, test_mode: false, capabilities: { online: true },
        }],
      } }) });
      return;
    }
    if (url.pathname.endsWith('/checkout')) {
      const state = failed
        ? { code: 'failed', label: language === 'en' ? 'Payment failed' : 'Paiement refusé', recoverable: true }
        : { code: 'requires_action', label: language === 'en' ? 'Customer action required' : 'Action client requise', recoverable: true };
      await route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: {
        order: { id: 42, order_number: 'SALE-GATE-42' },
        payment: { id: 7, status: failed ? 'failed' : 'requires_action', state, checkout_url: failed ? null : `https://checkout.${provider}.test/session` },
        account_creation: null,
      } }) });
      return;
    }
    await route.fallback();
  });
}

async function fillAndSubmit(page: Page, language: 'fr' | 'en'): Promise<void> {
  await page.getByLabel(language === 'en' ? 'First name' : 'Prénom').fill('Gate');
  await page.getByLabel(language === 'en' ? 'Last name' : 'Nom', { exact: true }).fill('Provider');
  await page.getByLabel(language === 'en' ? 'Email' : 'E-mail').fill('gate@example.test');
  await page.getByLabel(language === 'en' ? 'Address' : 'Adresse', { exact: true }).fill('Gate street');
  await page.getByLabel(language === 'en' ? 'Postal code' : 'Code postal').fill('1000');
  await page.getByLabel(language === 'en' ? 'City' : 'Ville').fill('Lausanne');
  await page.getByLabel(language === 'en' ? 'I accept the terms and conditions' : 'J’accepte les conditions générales de vente').check();
  await page.getByRole('button', { name: language === 'en' ? 'Place order' : 'Commander' }).focus();
  await expect(page.getByRole('button', { name: language === 'en' ? 'Place order' : 'Commander' })).toBeFocused();
  await page.keyboard.press('Enter');
}

async function providerJourney(context: BrowserContext, provider: string, language: 'fr' | 'en', mobile: boolean): Promise<Record<string, unknown>> {
  const page = await context.newPage();
  await page.setViewportSize(mobile ? { width: 390, height: 844 } : { width: 1280, height: 900 });
  await mockCheckout(page, provider, language);
  await page.goto(cmsPath(`/checkout?channel=web-main&cart_token=${token}&lang=${language}`));
  await expect(page.getByRole('heading', { name: language === 'en' ? 'Guest checkout' : 'Commande invitée' })).toBeVisible();
  await expect(page.getByLabel(language === 'en' ? 'Payment' : 'Paiement')).toHaveValue('real_checkout');
  await expect(page.locator('.checkout-test-mode')).toHaveCount(0);
  const formBox = await page.locator('[data-checkout-form]').boundingBox();
  const summaryBox = await page.locator('aside').boundingBox();
  if (mobile) expect(Number(summaryBox?.y)).toBeGreaterThan(Number(formBox?.y));
  await fillAndSubmit(page, language);
  const action = page.getByRole('link', { name: language === 'en' ? 'Continue payment' : 'Continuer le paiement' });
  await expect(action).toBeVisible();
  await expect(action).toHaveAttribute('href', `https://checkout.${provider}.test/session`);
  const snapshot = {
    heading: await page.getByRole('heading').first().textContent(),
    state: await page.locator('[data-checkout-summary] p').first().textContent(),
    action: await action.textContent(),
    meaningful_step_count: 2,
    mobile,
    language,
  };
  await page.close();
  return snapshot;
}

test.describe('Gate M5 interchangeabilité des providers de paiement', () => {
  test.skip(!enabled, 'E2E_BASE_URL is required');

  test('keeps Stripe and Revolut journeys identical on desktop, mobile, FR and EN', async ({ context }) => {
    test.setTimeout(120_000);
    for (const language of ['fr', 'en'] as const) {
      for (const mobile of [false, true]) {
        const stripe = await providerJourney(context, 'stripe_checkout', language, mobile);
        const revolut = await providerJourney(context, 'revolut_checkout', language, mobile);
        expect(revolut).toEqual(stripe);
      }
    }
  });

  test('offers the same recovery path after refusal without dead end', async ({ page }) => {
    await mockCheckout(page, 'revolut_checkout', 'fr', true);
    await page.goto(cmsPath(`/checkout?channel=web-main&cart_token=${token}&lang=fr`));
    await fillAndSubmit(page, 'fr');
    const retry = page.getByRole('button', { name: 'Réessayer ou choisir un autre moyen' });
    await expect(retry).toBeVisible();
    await retry.click();
    await expect(page.locator('[data-checkout-form]')).toBeVisible();
    await expect(page.getByLabel('Paiement')).toBeEnabled();
  });
});
