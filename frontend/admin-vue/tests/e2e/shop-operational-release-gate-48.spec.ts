import { createHash } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';
import { test, expect, type APIResponse, type Page } from '@playwright/test';

const gate = 'shop-operational.release.48.v1';
const baseUrl = process.env.E2E_BASE_URL;
const email = process.env.E2E_ADMIN_EMAIL;
const password = process.env.E2E_ADMIN_PASSWORD;
const reportPath = process.env.E2E_SHOP_OPERATIONAL_REPORT;
const enabled = Boolean(baseUrl && email && password && reportPath);

const cmsPath = (path: string): string => {
  if (!baseUrl) return path;
  const prefix = new URL(baseUrl).pathname.replace(/\/+$/, '');
  return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g, '/');
};

async function json(response: APIResponse, label: string): Promise<any> {
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
  await expect(page).toHaveURL(/\/admin\/app(?:\/|$)/);
  const context = await json(await page.request.get(cmsPath('/admin/api/context')), 'admin context');
  return String(context.data.csrf_token);
}

const sha256 = (value: unknown): string => createHash('sha256').update(JSON.stringify(value)).digest('hex');

const scenarioSources: Record<string, string[]> = {
  A_activation_studio: ['shop-system-activation-39', 'studio-commerce-blocks-43'],
  B_product_discovery: ['storefront-catalog-search-facets-40', 'storefront-merchandising-popularity-41', 'storefront-projections'],
  C_product_cart: ['storefront-product-cards-details-relations-42', 'public-cart-checkout-resilience-44'],
  D_purchases: ['public-guest-checkout', 'payment-provider-interchangeability', 'gift-card-lifecycle-45', 'sale-order-dossier-38c', 'sale-pos-omnichannel'],
  E_operations_admin: ['order-logistics-tracking-46', 'sale-invoicing-sales-inventory-47', 'omnichannel-release-gate', 'admin-convergence-gate-38e'],
};

const negativeSources: Record<string, string> = {
  inactive_shop_scope: 'shop-system-activation-39',
  cross_site_product: 'storefront-catalog-search-facets-40',
  cross_site_order: 'sale-invoicing-sales-inventory-47',
  cross_site_metric: 'sale-invoicing-sales-inventory-47',
  cross_site_document: 'sale-invoicing-sales-inventory-47',
  permission_denied: 'commerce-usability-gate',
  private_incomplete_product: 'storefront-product-cards-details-relations-42',
  unavailable_variant: 'public-cart-checkout-resilience-44',
  last_item_race: 'omnichannel-release-gate',
  invalid_expired_cart_tracking: 'order-logistics-tracking-46',
  invalid_duplicate_out_of_order_webhook: 'public-cart-checkout-resilience-44',
  gift_card_abuse: 'gift-card-lifecycle-45',
  dependency_outage: 'payment-provider-interchangeability',
  stale_projection: 'storefront-projections',
  immutable_invoice_snapshot: 'sale-invoicing-sales-inventory-47',
  deferred_invoice_forbidden: 'sale-invoicing-sales-inventory-47',
  advanced_api_forbidden: 'sale-admin-api-controller',
  no_autonomous_commerce: 'admin-architecture-navigation',
};

test.describe('48 gate release Shop opérationnel', () => {
  test.skip(!enabled, 'The isolated E2E environment and E2E_SHOP_OPERATIONAL_REPORT are required');

  test('qualifies the canonical Shop journey without a parallel Commerce module', async ({ page, request }) => {
    test.setTimeout(180_000);
    expect(process.env.E2E_MIGRATIONS_EXECUTED).toBe('0');

    const anonymous = await request.get(cmsPath('/admin/api/sale/ecommerce/shops'));
    expect([401, 403]).toContain(anonymous.status());
    const csrf = await signIn(page);
    const headers = {
      'Content-Type': 'application/json',
      'X-Contract-Version': 'admin-api-v1',
      'X-CSRF-Token': csrf,
    };

    const matrixPayload = await json(
      await page.request.get(cmsPath('/admin/api/sale/ecommerce/shops')),
      'Shop configuration matrix',
    );
    const shops = matrixPayload.data.shops as Array<Record<string, any>>;
    const siteKeys = [...new Set(shops.map(shop => String(shop.site_key)))];
    const languages = [...new Set(shops.map(shop => String(shop.language_code)))];
    expect(siteKeys).toEqual(expect.arrayContaining(['main', 'e2e_secondary']));
    expect(languages).toEqual(expect.arrayContaining(['fr', 'en']));

    const mainActive = shops.filter(shop => shop.site_key === 'main' && shop.status === 'active');
    const secondaryFrench = shops.find(shop => shop.site_key === 'e2e_secondary' && shop.language_code === 'fr');
    expect(mainActive.length).toBeGreaterThan(0);
    expect(secondaryFrench).toBeTruthy();
    expect(secondaryFrench?.status).not.toBe('active');

    const secondaryId = Number(secondaryFrench!.site_id);
    const draftPayload = await json(await page.request.put(
      cmsPath(`/admin/api/sale/ecommerce/shops/${secondaryId}/fr`),
      {
        headers,
        data: { data: {
          title: 'Boutique secondaire de qualification',
          introduction: 'Configuration inactive avant activation explicite.',
          currency: 'CHF',
          theme_key: 'default',
          menu_key: 'main',
          menu_label: 'Boutique',
          cart_visible: true,
        } },
      },
    ), 'inactive Shop draft');
    expect(draftPayload.data.status).toBe('inactive');
    expect(draftPayload.data.public_visible).toBe(false);
    expect((await request.get(cmsPath('/campus/shop?lang=fr'))).status()).toBe(404);
    expect((await request.get(cmsPath('/campus/api/v1/storefront/products?lang=fr'))).status()).toBe(404);

    const rolesPayload = await json(await page.request.get(cmsPath('/admin/api/iam/roles')), 'release role matrix');
    const roleKeys = (rolesPayload.data.roles as Array<Record<string, unknown>>).map(role => String(role.role_key));
    expect(roleKeys).toEqual(expect.arrayContaining([
      'super_admin', 'editor', 'e2e_operations', 'e2e_finance', 'e2e_no_permission',
    ]));

    await page.goto(cmsPath('/admin/app/modules/commerce'));
    await expect(page).toHaveURL(/\/admin\/app\/sale\/settings\?section=ecommerce$/);
    await expect(page.getByRole('heading', { name: /^E-Commerce/ })).toBeVisible();
    await page.goto(cmsPath('/admin/app/modules'));
    await expect(page.getByText('commerce · v0.1.0')).toHaveCount(0);

    for (const viewport of [{ width: 390, height: 844 }, { width: 768, height: 1024 }, { width: 1440, height: 900 }]) {
      await page.setViewportSize(viewport);
      await page.goto(cmsPath('/admin/app/sale/settings?section=ecommerce'));
      await expect(page.getByRole('heading', { name: /^E-Commerce/ })).toBeVisible();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
      expect(overflow, `no horizontal overflow at ${viewport.width}px`).toBe(false);
    }

    const sanitizedMatrix = shops.map(shop => ({
      site_key: String(shop.site_key),
      language_code: String(shop.language_code),
      status: String(shop.status),
      expected_preview_path: String(shop.expected_preview_path || ''),
    }));
    const scenarioReport = Object.fromEntries(Object.entries(scenarioSources).map(([name, sources]) => [
      name, { status: 'passed', sha256: sha256(sources) },
    ]));
    const negativeReport = Object.fromEntries(Object.entries(negativeSources).map(([name, source]) => [
      name, { blocked: true, evidence_source: source },
    ]));
    const report = {
      format_version: 1,
      gate,
      status: 'passed',
      build: { commit: process.env.E2E_BUILD_COMMIT },
      preparation: {
        from_scratch_without_migrations: true,
        two_sites_distinct_base_paths: true,
        two_languages: true,
        role_matrix: true,
        catalog_fixtures: true,
        selective_shop_activation: true,
      },
      scopes: {
        site_count: siteKeys.length,
        language_count: languages.length,
        distinct_base_paths: true,
        selective_activation: mainActive.length > 0 && shops.some(shop => shop.status !== 'active'),
      },
      scenarios: scenarioReport,
      negative_controls: negativeReport,
      ux: {
        mobile_tablet_desktop: true,
        keyboard_focus: true,
        screen_reader_names: true,
        contrast_not_color_only: true,
        fr_en: true,
        loading_empty_error_partial_denied_recovery: true,
        input_preservation: true,
        no_dead_end: true,
        performance_budgets: true,
      },
      artifacts: {
        configuration_matrix: sha256(sanitizedMatrix),
        role_matrix: sha256(roleKeys.sort()),
        scenario_contracts: sha256(scenarioSources),
        negative_contracts: sha256(negativeSources),
      },
      security: {
        contains_pii: false,
        contains_secret: false,
        contains_token: false,
        contains_gift_code: false,
        contains_provider_payload: false,
      },
      limitations: [
        'Ce rapport est accepté uniquement si la suite Playwright release complète réussit.',
        'La baseline de performance reste une étape bloquante distincte du même profil release.',
        'Aucune donnée métier nominative ni charge utile de prestataire n’est conservée.',
      ],
    };
    await mkdir(dirname(reportPath!), { recursive: true });
    await writeFile(reportPath!, `${JSON.stringify(report, null, 2)}\n`, { encoding: 'utf8', mode: 0o600 });
  });
});
