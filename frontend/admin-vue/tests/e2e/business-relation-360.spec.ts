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

async function createRelationFixture(page: Page): Promise<void> {
  const contextResponse = await page.request.get(cmsPath('/admin/api/context'));
  expect(contextResponse.ok(), 'admin context is available').toBeTruthy();
  const context = await contextResponse.json();
  const stamp = `${Date.now()}-${Math.floor(Math.random() * 10000)}`;
  const response = await page.request.post(cmsPath(`/admin/api/business/companies?site_id=${context.data.site.id}`), {
    headers: {
      'Content-Type': 'application/json',
      'X-Contract-Version': 'admin-api-v1',
      'X-CSRF-Token': context.data.csrf_token,
    },
    data: { data: { name: `Relation 360 E2E ${stamp}`, status: 'client', email: `relation-360-${stamp}@example.test` } },
  });
  expect(response.ok(), `relation fixture failed with HTTP ${response.status()}`).toBeTruthy();
}

test.describe('38b Relation 360 CRM', () => {
  test.skip(!enabled, 'Dedicated E2E admin environment is required');
  test.setTimeout(120_000);

  test.beforeEach(async ({ page }) => { await signIn(page); await createRelationFixture(page); });

  test('shows the canonical Relation 360 sections and keeps technical payload collapsed', async ({ page }) => {
    test.setTimeout(120_000);
    await page.goto(cmsPath('/admin/app/business/relations'));
    await expect(page.getByRole('heading', { name: 'Relations' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Tous', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Prospects' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Clients' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Fournisseurs' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'À suivre' })).toBeVisible();

    const row = page.locator('.relations-table tbody tr').first();
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: /^Voir / }).click();
    const modal = page.locator('.business-modal');
    await expect(modal.getByRole('navigation', { name: 'Sections de la relation' })).toBeVisible();
    for (const heading of ['Vue d’ensemble', 'Chronologie commune', 'Commandes', 'Facturation et paiements', 'Livraisons et retours', 'Formulaires', 'Consentements']) {
      await expect(modal.getByRole('heading', { name: heading, exact: true })).toBeVisible();
    }
    const technical = modal.getByText('Détails techniques des projections');
    if (await technical.count()) await expect(technical.locator('xpath=..')).not.toHaveAttribute('open', '');

    await modal.getByRole('button', { name: /fermer/i }).click();
    await page.evaluate(() => localStorage.setItem('amcms.admin.uiLanguage', 'en'));
    await page.reload();
    const englishRow = page.locator('.relations-table tbody tr').first();
    await expect(englishRow).toBeVisible();
    await englishRow.getByRole('button', { name: /^Voir / }).click();
    await expect(modal.getByRole('navigation', { name: 'Relation sections' })).toBeVisible();
    await expect(modal.getByRole('heading', { name: 'Overview', exact: true })).toBeVisible();
    await expect(modal.getByRole('heading', { name: 'Invoices and payments', exact: true })).toBeVisible();
  });

  test('relocates profile reconciliation from Sales and preserves the selected relation', async ({ page }) => {
    await page.goto(cmsPath('/admin/app/sale/advanced/identities?relation_type=contact&relation_id=17'));
    await expect(page).toHaveURL(/\/admin\/app\/business\/relations\/advanced\/profiles\?relation_type=contact&relation_id=17$/);
    await expect(page.getByText(/permissions de rapprochement|Rapprochement de profils/).first()).toBeVisible();

    await page.goto(cmsPath('/admin/app/sale'));
    const advanced = page.getByRole('navigation', { name: 'Outils avancés' });
    await expect(advanced.getByText(/identit/i)).toHaveCount(0);
  });

  test('keeps relation preferences and remains usable on mobile', async ({ page }) => {
    await page.goto(cmsPath('/admin/app/business/relations'));
    await page.locator('details.relations-menu--columns > summary').click();
    await page.getByLabel('Entreprise', { exact: true }).uncheck();
    await page.reload();
    await expect(page.locator('.relations-table thead').getByText('Entreprise', { exact: true })).toHaveCount(0);
    await page.setViewportSize({ width: 390, height: 820 });
    await expect(page.locator('.relations-cards')).toBeVisible();
    await expect(page.locator('.relations-table')).toBeHidden();
  });

  test('filters and paginates a long common timeline with the keyboard', async ({ page }) => {
    const timeline = Array.from({ length: 12 }, (_, index) => ({
      id: index + 1,
      kind: index % 2 ? 'sale' : 'form',
      summary: index === 11 ? 'Remboursement spécial' : `Activité ${index + 1}`,
      action: index === 11 ? 'business.sale.refund.completed' : 'business.form.submitted',
      created_at: `2026-07-${String(15 - index).padStart(2, '0')} 10:00:00`,
      metadata: { channel: index % 2 ? 'web' : 'admin', source_reference: `REF-${index + 1}` },
    }));
    await page.route('**/admin/api/business/relations/*/*/360*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: {
          relation: { roles: [{ role_key: 'client' }], identity_linked: false },
          next_action: null, alerts: [], timeline, orders: [], financial: [], fulfillments: [], forms: [],
          consents_available: false, projection_health: { degraded: false }, ownership: {},
        } }),
      });
    });
    await page.goto(cmsPath('/admin/app/business/relations'));
    const row = page.locator('.relations-table tbody tr').first();
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: /^Voir / }).click();
    const modal = page.locator('.business-modal');
    await expect(modal.getByText('Page 1 / 2')).toBeVisible();
    await modal.getByRole('button', { name: 'Suivant' }).click();
    await expect(modal.getByText('Remboursement spécial', { exact: true })).toBeVisible();
    const search = modal.getByLabel('Rechercher');
    await search.focus();
    await page.keyboard.type('spécial');
    await expect(modal.getByText('Remboursement spécial', { exact: true })).toBeVisible();
    await expect(modal.getByText(/^Activité 1$/)).toHaveCount(0);
  });
});
