import { test, expect, type APIResponse, type Locator, type Page } from '@playwright/test';

const baseUrl = process.env.E2E_BASE_URL;
const email = process.env.E2E_ADMIN_EMAIL;
const password = process.env.E2E_ADMIN_PASSWORD;
const hasDedicatedEnvironment = Boolean(baseUrl && email && password);

type ApiEnvelope<TData = Record<string, unknown>> = {
  data: TData;
  meta: { contract: string; [key: string]: unknown };
};

type AdminContextData = {
  csrf_token: string;
  site: { id: number };
  capabilities: Record<string, boolean>;
};

type CrmFixture = {
  context: ApiEnvelope<AdminContextData>;
  stamp: number;
  companyName: string;
  companyEmail: string;
  contactName: string;
  contactEmail: string;
  contactId: number;
};

function cmsPath(path: string): string {
  if (!baseUrl) return path;
  const configured = new URL(baseUrl);
  const prefix = configured.pathname.replace(/\/+$/, '');
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

async function jsonEnvelope<TData>(response: APIResponse, expectedContract: string, label: string): Promise<ApiEnvelope<TData>> {
  const text = await response.text();
  expect(response.status(), `${label} returned HTTP 500:\n${text}`).not.toBe(500);
  expect(response.ok(), `${label} failed with HTTP ${response.status()}:\n${text}`).toBeTruthy();
  const payload = JSON.parse(text) as ApiEnvelope<TData>;
  expect(payload.meta.contract, `${label} contract`).toBe(expectedContract);
  return payload;
}

function adminHeaders(csrfToken: string): Record<string, string> {
  return {
    'Content-Type': 'application/json',
    'X-Contract-Version': 'admin-api-v1',
    'X-CSRF-Token': csrfToken,
  };
}

async function postJson<T>(page: Page, path: string, csrfToken: string, data: Record<string, unknown>, contract: string, label: string): Promise<T> {
  const payload = await jsonEnvelope<T>(
    await page.request.post(cmsPath(path), {
      headers: adminHeaders(csrfToken),
      data: { data },
    }),
    contract,
    label,
  );
  return payload.data;
}

async function getJson<T>(page: Page, path: string): Promise<T> {
  const response = await page.request.get(cmsPath(path));
  expect(response.ok(), `${path} returned ${response.status()}`).toBeTruthy();
  return await response.json() as T;
}

async function openRelationRowMenu(row: Locator): Promise<void> {
  await row.evaluate((element) => element.scrollIntoView({ block: 'center', inline: 'nearest' }));
  const details = row.locator('details.relations-menu').first();
  const isOpen = await details.evaluate((element) => (element as HTMLDetailsElement).open);
  if (!isOpen) {
    await details.locator('> summary').click();
  }
}

function relationRowMenuButton(row: Locator, label: string): Locator {
  return row.locator('details.relations-menu button').filter({ hasText: label }).first();
}

async function closeBusinessModal(page: Page, acceptDirtyConfirmation = false): Promise<void> {
  const modal = page.locator('.business-modal');
  if (acceptDirtyConfirmation) {
    page.once('dialog', async (dialog) => {
      await dialog.accept();
    });
  }
  await modal.getByRole('button', { name: 'Fermer' }).click();
  await expect(modal).toBeHidden();
}

async function returnToRelationsIfNeeded(page: Page): Promise<void> {
  const back = page.getByRole('button', { name: 'Retour aux relations' });
  if (await back.isVisible().catch(() => false)) {
    await back.click();
    await expect(page.getByRole('heading', { name: 'Relations' })).toBeVisible();
  }
}

async function openRelations(page: Page): Promise<void> {
  const responsePromise = page.waitForResponse((response) => {
    if (response.request().method() !== 'GET') return false;
    const url = new URL(response.url());
    return url.pathname.endsWith('/admin/api/business/relations');
  }, { timeout: 30_000 });
  await page.getByRole('button', { name: 'Relations' }).click();
  expect((await responsePromise).ok(), 'Initial relations request succeeds').toBeTruthy();
  await expect(page.getByText('Chargement des relations...')).toBeHidden();
}

async function searchRelations(page: Page, query: string): Promise<void> {
  const responsePromise = page.waitForResponse((response) => {
    if (response.request().method() !== 'GET') return false;
    const url = new URL(response.url());
    return url.pathname.endsWith('/admin/api/business/search') && url.searchParams.get('q') === query;
  }, { timeout: 30_000 });
  await page.getByPlaceholder(/Recherche globale/i).fill(query);
  expect((await responsePromise).ok(), 'Global relations search request succeeds').toBeTruthy();
  await expect(page.getByText('Recherche...')).toBeHidden();
}

async function createCrmFixture(page: Page): Promise<CrmFixture> {
  const context = await jsonEnvelope<AdminContextData>(
    await page.request.get(cmsPath('/admin/api/context')),
    'admin.context.v1',
    'admin context',
  );
  expect(context.data.capabilities['business.crm.manage'], 'E2E account can manage Business CRM').toBeTruthy();
  expect(context.data.capabilities['business.memo.manage'], 'E2E account can manage Business memos').toBeTruthy();

  const stamp = Date.now();
  const companyName = `E2E Organisation ${stamp}`;
  const companyEmail = `e2e.company.${stamp}@example.test`;
  const contactName = `E2E Personne ${stamp}`;
  const contactEmail = `e2e.crm.${stamp}@example.test`;
  const siteQuery = `site_id=${context.data.site.id}`;

  const companyPayload = await postJson<{ company: { id: number } }>(page, `/admin/api/business/companies?${siteQuery}`, context.data.csrf_token, {
    name: companyName,
    status: 'prospect',
    email: companyEmail,
  }, 'admin.business.companies.show.v1', 'company creation');
  const companyId = companyPayload.company.id;

  const contactPayload = await postJson<{ contact: { id: number } }>(page, `/admin/api/business/contacts?${siteQuery}`, context.data.csrf_token, {
    company_id: companyId,
    display_name: contactName,
    email: contactEmail,
    phone: '+41 21 000 00 00',
    status: 'client',
  }, 'admin.business.contacts.show.v1', 'contact creation');
  const contactId = contactPayload.contact.id;

  await postJson(page, `/admin/api/business/relations/contact/${contactId}/memos?${siteQuery}`, context.data.csrf_token, {
    title: `E2E mémo ${stamp}`,
    body: 'Mémo smoke UX Business.',
    visibility: 'internal',
  }, 'admin.business.relations.memos.store.v1', 'memo creation');

  return { context, stamp, companyName, companyEmail, contactName, contactEmail, contactId };
}

test.describe('business CRM UX smoke', () => {
  test.skip(!hasDedicatedEnvironment, 'Dedicated E2E_BASE_URL, E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD are required');
  test.setTimeout(90_000);

  test.beforeEach(async ({ page }) => {
    await signIn(page);
  });

  test('covers relations, memo, message and consent entry points without public CRM headless exposure', async ({ page }) => {
    const { stamp, companyName, companyEmail, contactName, contactEmail, contactId } = await createCrmFixture(page);

    await page.goto(cmsPath('/admin/app/business'));
    await expect(page.getByRole('button', { name: 'Relations' })).toBeVisible();
    await openRelations(page);

    await expect(page.getByRole('heading', { name: 'Relations' })).toBeVisible();
    await expect(page.getByPlaceholder(/Recherche globale/i)).toBeVisible();
    const filtersMenu = page.locator('details.relations-menu--filters > summary[aria-label="Filtres relations"]');
    await expect(filtersMenu).toBeVisible();
    await filtersMenu.click();
    await expect(page.getByLabel('Type relation')).toBeVisible();
    await expect(page.getByLabel('Tri relation')).toBeVisible();
    await filtersMenu.click();

    const columnsMenu = page.locator('details.relations-menu--columns > summary[aria-label="Colonnes relations"]');
    await expect(columnsMenu).toBeVisible();

    const menu = page.locator('details.relations-menu--actions > summary[aria-label="Actions relations"]');
    await expect(menu).toBeVisible();
    await expect(page.getByRole('button', { name: 'Nouvelle relation' })).toBeVisible();
    await menu.click();
    await expect(page.getByRole('button', { name: 'Nouveau contact' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Mémos', exact: true })).toBeVisible();
    await menu.click();

    await searchRelations(page, `E2E ${stamp}`);
    const contactRow = page.locator('.relations-table tbody tr').filter({ hasText: contactName }).first();
    const companyRow = page.locator('.relations-table tbody tr').filter({ hasText: companyName }).filter({ hasText: companyEmail }).first();
    await expect(contactRow).toBeVisible();
    await expect(companyRow).toBeVisible();
    await expect(contactRow).toContainText(contactEmail);

    await expect(contactRow.locator('.relations-indicators')).toContainText('M 1');
    await openRelationRowMenu(contactRow);
    await relationRowMenuButton(contactRow, 'Mémos').click();
    await expect(page.getByText('Retour aux relations')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Mémos' })).toBeVisible();
    await page.getByRole('button', { name: 'Retour aux relations' }).click();

    await page.getByRole('button', { name: 'Nouvelle relation' }).first().click();
    const relationModal = page.locator('.business-modal');
    await expect(relationModal.getByRole('heading', { name: 'Fiche relation' })).toBeVisible();
    await expect(relationModal.getByRole('heading', { name: 'Création rapide relation' })).toBeVisible();
    await expect(relationModal.getByRole('button', { name: 'Individu' })).toHaveClass(/active/);
    await expect(relationModal.getByLabel('Nom affiché')).toBeVisible();
    await relationModal.getByRole('button', { name: 'Entreprise' }).click();
    await expect(relationModal.getByLabel('Site web')).toBeVisible();
    await closeBusinessModal(page);

    const freshContactRow = page.locator('.relations-table tbody tr').filter({ hasText: contactName }).first();
    await openRelationRowMenu(freshContactRow);
    await relationRowMenuButton(freshContactRow, 'Éditer').click();
    await expect(relationModal.getByRole('heading', { name: 'Fiche relation' })).toBeVisible();
    await expect(relationModal.getByLabel('Email')).toHaveValue(contactEmail);
    await closeBusinessModal(page);

    const viewContactRow = page.locator('.relations-table tbody tr').filter({ hasText: contactName }).first();
    await viewContactRow.getByRole('button', { name: `Voir ${contactName}` }).click();
    await expect(relationModal.getByLabel('Rechercher')).toBeVisible();
    await expect(relationModal.getByLabel('Type')).toBeVisible();
    await expect(relationModal.getByLabel('Canal')).toBeVisible();
    await relationModal.getByLabel('Rechercher').fill('activité absente e2e');
    await expect(relationModal.getByText('Aucune activité ne correspond aux filtres.')).toBeVisible();
    await relationModal.getByLabel('Rechercher').fill('');
    await closeBusinessModal(page);

    await freshContactRow.getByRole('button', { name: `Ajouter un mémo pour ${contactName}` }).click();
    await expect(relationModal.getByRole('heading', { name: 'Nouveau mémo' })).toBeVisible();
    await expect(relationModal.getByLabel('Contact')).toHaveValue(String(contactId));
    await closeBusinessModal(page, true);
    await returnToRelationsIfNeeded(page);

    const afterMemoRow = page.locator('.relations-table tbody tr').filter({ hasText: contactName }).first();
    await openRelationRowMenu(afterMemoRow);
    await relationRowMenuButton(afterMemoRow, 'Nouveau message').click();
    await expect(relationModal.getByRole('heading', { name: 'Nouveau message', level: 2 })).toBeVisible();
    await closeBusinessModal(page, true);

    const afterMessageRow = page.locator('.relations-table tbody tr').filter({ hasText: contactName }).first();
    await openRelationRowMenu(afterMessageRow);
    await relationRowMenuButton(afterMessageRow, 'Consentement').click();
    await expect(relationModal.getByRole('heading', { name: 'Consentements', level: 2 })).toBeVisible();
    await closeBusinessModal(page);

    const users = await getJson<{ data: { users: Array<{ id: number; email?: string }> } }>(page, '/admin/api/business/iam/available-users?include_assigned=true');
    expect(Array.isArray(users.data.users)).toBeTruthy();
    const ids = users.data.users.map((user) => user.id);
    expect(new Set(ids).size).toBe(ids.length);

    const openApi = await getJson<{ paths: Record<string, unknown> }>(page, '/api/v1/openapi.json');
    const publicCrmPaths = Object.keys(openApi.paths).filter((path) => /\/(?:business|crm|relations|memos|contacts|companies)(?:\/|$)/.test(path));
    expect(publicCrmPaths).toEqual([]);
  });

  test('keeps the relations workflow usable on a mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const { stamp, contactName, contactEmail } = await createCrmFixture(page);

    await page.goto(cmsPath('/admin/app/business'));
    await openRelations(page);
    await searchRelations(page, `E2E ${stamp}`);

    await expect(page.locator('.relations-table')).toBeHidden();
    const card = page.locator('.relation-card').filter({ hasText: contactName }).first();
    await expect(card).toBeVisible();
    await expect(card).toContainText(contactEmail);
    await expect(card.getByRole('button', { name: `Voir ${contactName}` })).toBeVisible();
    await expect(card.getByRole('button', { name: `Ajouter un mémo pour ${contactName}` })).toBeVisible();
    await card.getByRole('button', { name: `Gérer les consentements de ${contactName}` }).click();
    await expect(page.locator('.business-modal').getByRole('heading', { name: 'Consentements', level: 2 })).toBeVisible();

    const horizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1);
    expect(horizontalOverflow, 'mobile CRM viewport has no page-level horizontal overflow').toBeFalsy();
  });
});
