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

test.describe('38d Opérations, produits, stock, offres et Audiences', () => {
  test.skip(!enabled, 'Dedicated E2E admin environment is required');

  test('shows only permission-aware actionable queues on desktop, mobile and keyboard', async ({ page }) => {
    await signIn(page);
    await page.route('**/admin/api/business/dashboard', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({
      counters: { client: 999 }, recent_relations: [{ id: 1, type: 'contact', display_name: 'Vanity row' }], latest_memos: [], latest_messages: [], alerts: [],
      actionable: { tasks: [
        { key: 'inventory.blocked', queue: 'inventory', priority: 5, count: 3, label: 'Commandes en attente de stock', explanation: 'Une réception peut débloquer une commande.', route: '/business/products-stock?view=low_stock', advanced: false },
        { key: 'offers.conflicts', queue: 'offers', priority: 10, count: 2, label: 'Offres potentiellement en conflit', explanation: 'Comparer portée, période et priorité.', route: '/business/offers-marketing?filter=conflict', advanced: false },
        { key: 'inventory.inconsistent', queue: 'advanced', priority: 1, count: 1, label: 'Écarts de ledger à diagnostiquer', explanation: 'Réconcilier sans réécrire le ledger.', route: '/sale/advanced/stock?alert=inconsistent', advanced: true },
      ], summary: { inventory: 3, offers: 2, advanced: 1 } },
    }) }));

    await page.goto(cmsPath('/admin/app/business'));
    await expect(page.getByRole('heading', { name: 'À traiter' })).toBeVisible();
    await expect(page.getByText('Commandes en attente de stock')).toBeVisible();
    await expect(page.getByText('Écarts de ledger à diagnostiquer')).toBeVisible();
    await expect(page.getByText('Vanity row')).toHaveCount(0);
    const firstTask = page.getByRole('link', { name: /Écarts de ledger à diagnostiquer/ });
    await firstTask.focus();
    await expect(firstTask).toBeFocused();

    await page.setViewportSize({ width: 390, height: 820 });
    await expect(page.locator('.operations-work-queue')).toBeVisible();
    await expect(page.getByRole('link', { name: /Offres potentiellement en conflit/ })).toBeVisible();
  });

  test('guides an offer through preview, conflict and Audience semantics in FR/EN', async ({ page }) => {
    test.setTimeout(120_000);
    await signIn(page);
    let previewCalls = 0;
    await page.route('**/admin/api/business/catalog/discounts/preview', async route => {
      previewCalls++;
      if (previewCalls === 1) {
        await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ error: { message: 'Conflit temporaire de prévisualisation' } }) });
        return;
      }
      const request = route.request().postDataJSON() as { data?: { conflict_acknowledged?: boolean } };
      const acknowledged = Boolean(request?.data?.conflict_acknowledged);
      await route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ preview: {
        affected_products: 4, channel: 'ecommerce', audience: { rule: 'clients-fideles', estimated_count: 18, is_consent: false },
        conflicts: [{ id: 9, name: 'Offre existante' }], activation_allowed: acknowledged,
      } }) });
    });

    await page.goto(cmsPath('/admin/app/business/offers-marketing'));
    await page.getByRole('button', { name: 'Nouvelle réduction' }).first().click();
    const offerDialog = page.getByRole('dialog', { name: 'Éditer réduction' });
    await expect(offerDialog.getByText('1. Objectif')).toBeVisible();
    await offerDialog.getByLabel('Nom de l’offre').fill('Offre guidée 38d');
    await offerDialog.getByRole('button', { name: 'Suivant' }).click();
    await expect(offerDialog.getByText(/ne constitue jamais un consentement marketing/i)).toBeVisible();
    await offerDialog.getByLabel('Objet concerné').fill('1');
    await offerDialog.getByLabel('Canal').selectOption('ecommerce');
    await offerDialog.getByLabel('Audience facultative').fill('clients-fideles');
    await offerDialog.getByRole('button', { name: 'Suivant' }).click();
    await offerDialog.getByLabel('Valeur').fill('10');
    await offerDialog.getByRole('button', { name: 'Suivant' }).click();
    await offerDialog.getByRole('button', { name: 'Suivant' }).click();
    await offerDialog.getByRole('button', { name: 'Suivant' }).click();
    await offerDialog.getByRole('button', { name: 'Prévisualiser' }).click();
    await expect(page.getByText(/Conflit temporaire de prévisualisation|Aperçu de l’offre impossible/)).toBeVisible();
    await offerDialog.getByRole('button', { name: 'Prévisualiser' }).click();
    await expect(offerDialog.getByText('4 produit(s) concerné(s)')).toBeVisible();
    await expect(offerDialog.getByText(/n’est pas une preuve de consentement/i)).toBeVisible();
    const acknowledgement = offerDialog.getByLabel(/J’ai examiné la priorité/);
    await acknowledgement.check();
    await expect(offerDialog.getByRole('button', { name: 'Enregistrer' })).toBeEnabled();

    await page.evaluate(() => localStorage.setItem('amcms.admin.uiLanguage', 'en'));
    await page.goto(cmsPath('/admin/app/business/offers-marketing/audiences'));
    await expect(page.getByRole('heading', { name: 'Audiences' }).last()).toBeVisible();
    await expect(page.getByText(/never constitutes marketing consent/i)).toBeVisible();
  });

  test('keeps product stock read-only and opens a variant inventory adjustment above the product editor', async ({ page }) => {
    test.setTimeout(120_000);
    await signIn(page);
    await page.goto(cmsPath('/admin/app/business/products-stock'));
    // Gift cards and services legitimately appear before physical products in
    // the sorted catalogue and expose a non-tracked stock cell. Exercise a
    // genuinely stock-tracked product so the test proves the inventory path.
    const stockCell = page.locator('.catalog-stock-cell').filter({ hasText: /disponible/ }).first();
    await expect(stockCell).toBeVisible({ timeout: 60_000 });
    await stockCell.click();
    const productDialog = page.getByRole('dialog', { name: 'Modifier le stock' });
    await expect(productDialog).toBeVisible({ timeout: 60_000 });
    await expect(productDialog.getByLabel('Opération')).toHaveCount(0);
    await expect(productDialog.getByText('Ouvrir le ledger détaillé')).toHaveCount(0);
    const inventoryButton = productDialog.getByRole('button', { name: 'Modifier l’inventaire' }).first();
    await expect(inventoryButton).toBeVisible();
    await inventoryButton.click();
    await expect(page.getByRole('dialog', { name: 'Modifier l’inventaire' })).toBeVisible();
    await expect(productDialog).toBeAttached();
  });

  test('guides product media by storefront usage and reuses the visual library', async ({ page }) => {
    test.setTimeout(120_000);
    await signIn(page);
    await page.goto(cmsPath('/admin/app/business/products-stock'));
    const productRow=page.locator('.catalog-table-row:not(.is-archived)').first();
    await expect(productRow).toBeVisible({ timeout: 60_000 });
    await productRow.locator('.catalog-row-menu-summary').click();
    await productRow.getByRole('button',{name:'Médias',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Modifier les médias'});
    await expect(dialog.getByRole('heading',{name:'Images et médias du produit'})).toBeVisible({ timeout: 60_000 });
    await expect(dialog.getByRole('heading',{name:'Aperçu de la galerie boutique'})).toBeVisible();
    await expect(dialog.getByRole('button',{name:/Image principale Cartes/})).toBeVisible();
    await expect(dialog.getByRole('button',{name:'Médiathèque'})).toBeVisible();
    await expect(dialog.getByRole('button',{name:'Upload'})).toBeVisible();
    await dialog.getByRole('button',{name:/Document Notice/}).click();
    await expect(dialog.getByText('Affiché dans « Documents à télécharger »')).toBeVisible();
    await expect(dialog.getByText('Choisir ou téléverser le document')).toBeVisible();
  });

  test('returns unit quantities and purchase/sale valuations from the real inventory API', async ({ page }) => {
    await signIn(page);
    const response = await page.request.get(cmsPath('/admin/api/business/catalog/inventory?limit=100'), { headers: { 'X-Contract-Version': 'admin-api-v1' } });
    expect(response.ok()).toBeTruthy();
    const payload = await response.json();
    expect(payload.data.items.length).toBeGreaterThan(0);
    const item = payload.data.items[0];
    expect(item).toHaveProperty('currency');
    expect(item).toHaveProperty('unit_sale_price_minor');
    expect(item).toHaveProperty('physical_purchase_value_minor');
    expect(item).toHaveProperty('reserved_sale_value_minor');
    expect(item).toHaveProperty('available_sale_value_minor');
  });

  test('updates the unified inventory only through an explicit signed variation', async ({ page }) => {
    test.setTimeout(120_000);
    await signIn(page);
    let releaseInventory = () => {};
    const inventoryGate = new Promise<void>(resolve => { releaseInventory = resolve; });
    await page.route('**/admin/api/business/catalog/inventory**', async route => {
      await inventoryGate;
      await route.fulfill({ status: 200, contentType: 'application/json', body: envelope({
        items: [{ id: 12, business_variant_id: 7, stock_location_id: 3, sku: 'DEMO-GOURDE', product_name: 'Gourde', variant_name: 'Bleue', location_name: 'Stock principal', on_hand_quantity: 8, reserved_quantity: 2, available_quantity: 6, currency: 'CHF', physical_purchase_value_minor: 8000, reserved_sale_value_minor: 5800, available_sale_value_minor: 17400 }],
        locations: [{ id: 3, code: 'main', name: 'Stock principal' }], summary: { on_hand_quantity: 8, reserved_quantity: 2, available_quantity: 6, item_count: 1, currency: 'CHF', physical_purchase_value_minor: 8000, reserved_sale_value_minor: 5800, available_sale_value_minor: 17400 },
      }) });
    });
    await page.route('**/admin/api/business/catalog/variants/7/stock-movements', async route => {
      const payload = route.request().postDataJSON() as { data?: { preview?: boolean; quantity?: number } };
      expect(payload.data?.quantity).toBe(-2);
      await route.fulfill({ status: payload.data?.preview ? 200 : 201, contentType: 'application/json', body: envelope(payload.data?.preview ? {
        preview: { before: { on_hand: 8 }, after: { on_hand: 6 }, blocked: false, explanation: 'Aucune quantité engagée supplémentaire.' },
      } : { movement: { quantity: -2 } }) });
    });

    await page.goto(cmsPath('/admin/app/business/inventory'), { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('status', { name: 'Calcul de l’inventaire en cours' })).toBeVisible({ timeout: 60_000 });
    releaseInventory();
    await expect(page.getByRole('heading', { name: 'Inventaire' })).toHaveCount(0);
    const inventoryRow = page.locator('.operations-inventory__table-wrap tbody tr').filter({ hasText: 'DEMO-GOURDE' });
    await expect(inventoryRow).toHaveCount(1);
    await expect(inventoryRow.getByText(/8 u\. \/ 80[.,]00/)).toBeVisible();
    await expect(inventoryRow.getByText(/2 u\. \/ 58[.,]00/)).toBeVisible();
    await expect(inventoryRow.getByText(/6 u\. \/ 174[.,]00/)).toBeVisible();
    await expect(inventoryRow.getByText('CHF')).toHaveCount(0);
    await expect(inventoryRow.getByRole('button', { name: 'Saisir une variation' })).toBeVisible();
    await page.getByRole('button', { name: 'Saisir une variation' }).click();
    const dialog = page.getByRole('dialog', { name: 'Variation de stock' });
    await dialog.getByLabel('Variation').fill('-2');
    await dialog.getByLabel('Motif obligatoire').fill('Deux unités cassées');
    await dialog.getByRole('button', { name: 'Prévisualiser' }).click();
    await expect(dialog.getByText('Après 6')).toBeVisible();
    await dialog.getByRole('button', { name: 'Confirmer la variation' }).click();
    await expect(page.getByText('Variation de stock enregistrée dans le registre unifié.')).toBeVisible();
  });
});
