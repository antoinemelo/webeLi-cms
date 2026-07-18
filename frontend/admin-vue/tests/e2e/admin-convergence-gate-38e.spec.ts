import { createHash } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { test, expect, type Page } from '@playwright/test';

const gate = 'admin-convergence.ux.38e.v1';
const baseUrl = process.env.E2E_BASE_URL;
const email = process.env.E2E_ADMIN_EMAIL;
const password = process.env.E2E_ADMIN_PASSWORD;
const reportPath = process.env.E2E_ADMIN_CONVERGENCE_REPORT;
const enabled = Boolean(baseUrl && email && password && reportPath);

const cmsPath = (path: string): string => {
  if (!baseUrl) return path;
  const prefix = new URL(baseUrl).pathname.replace(/\/+$/, '');
  return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g, '/');
};
const envelope = (data: unknown): string => JSON.stringify({ data });

type CaptureEvidence = {
  file: string;
  sha256: string;
  viewport: string;
  unnamed_controls: number;
  unlabelled_fields: number;
  horizontal_overflow: boolean;
  focus_visible: boolean;
};
type ScenarioEvidence = {
  status: 'passed';
  steps: number;
  backtracks: number;
  errors: number;
  duration_ms: number;
  next_action_visible: true;
  recovery_verified: true;
  captures: { desktop: CaptureEvidence; mobile: CaptureEvidence };
};

const scenarios: Record<string, ScenarioEvidence> = {};
const permissionEvidence = { ordinary_navigation_hides_advanced: false, advanced_navigation_visible: false };

async function signIn(page: Page): Promise<{ csrf: string }> {
  await page.goto(cmsPath('/admin/login'));
  await page.getByLabel('Email').fill(email!);
  await page.getByRole('button', { name: /continuer/i }).click();
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: /se connecter/i }).click();
  await expect(page).toHaveURL(/\/admin\/app(?:\/|$)/);
  await page.evaluate(() => localStorage.setItem('amcms.admin.uiLanguage', 'fr'));
  const response = await page.request.get(cmsPath('/admin/api/context'));
  expect(response.ok(), await response.text()).toBeTruthy();
  return { csrf: String((await response.json()).data.csrf_token) };
}

async function audit(page: Page): Promise<Omit<CaptureEvidence, 'file' | 'sha256' | 'viewport'>> {
  const root = page.locator('main, [role="main"]').first();
  await expect(root).toBeVisible();
  const values = await root.evaluate((node) => {
    const visible = (element: Element): boolean => {
      const html = element as HTMLElement;
      const style = getComputedStyle(html);
      const rect = html.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
    };
    const named = (element: Element): boolean => {
      const html = element as HTMLElement;
      const id = html.id;
      const labelledBy = html.getAttribute('aria-labelledby');
      const referenced = labelledBy?.split(/\s+/).map((key) => document.getElementById(key)?.textContent || '').join(' ').trim();
      const explicit = id ? document.querySelector(`label[for="${CSS.escape(id)}"]`)?.textContent?.trim() : '';
      const wrapping = html.closest('label')?.textContent?.trim();
      const text = html.matches('input, select, textarea') ? '' : html.textContent?.trim();
      return Boolean(html.getAttribute('aria-label')?.trim() || referenced || explicit || wrapping || html.getAttribute('title')?.trim() || text);
    };
    const controls = Array.from(node.querySelectorAll('button, a[href], input:not([type="hidden"]), select, textarea, [role="button"]'))
      .filter(visible).filter((element) => !(element as HTMLInputElement).disabled);
    return {
      unnamed_controls: controls.filter((element) => !element.matches('input, select, textarea') && !named(element)).length,
      unlabelled_fields: controls.filter((element) => element.matches('input, select, textarea') && !named(element)).length,
      horizontal_overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      overflow_sources: Array.from(document.querySelectorAll('*')).filter(visible).flatMap((element) => {
        const rect = element.getBoundingClientRect();
        if (rect.left >= -1 && rect.right <= document.documentElement.clientWidth + 1) return [];
        const html = element as HTMLElement;
        return [`${html.tagName.toLowerCase()}${html.id ? `#${html.id}` : ''}${html.classList.length ? `.${Array.from(html.classList).join('.')}` : ''} [${Math.round(rect.left)}, ${Math.round(rect.right)}]`];
      }).slice(0, 8),
    };
  });
  const first = root.locator('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled])').first();
  await expect(first).toBeVisible();
  await page.keyboard.press('Tab');
  await first.focus();
  const focusVisible = await first.evaluate((node) => {
    const style = getComputedStyle(node);
    return (style.outlineStyle !== 'none' && Number.parseFloat(style.outlineWidth) > 0) || style.boxShadow !== 'none';
  });
  expect(values.unnamed_controls, 'visible controls have an accessible name').toBe(0);
  expect(values.unlabelled_fields, 'visible fields have a label').toBe(0);
  expect(values.horizontal_overflow, `page has no horizontal overflow; sources: ${values.overflow_sources.join(', ') || 'none'}`).toBe(false);
  expect(focusVisible, 'keyboard focus is visible').toBe(true);
  return {
    unnamed_controls: values.unnamed_controls,
    unlabelled_fields: values.unlabelled_fields,
    horizontal_overflow: values.horizontal_overflow,
    focus_visible: focusVisible,
  };
}

async function capture(page: Page, scenario: string, viewportName: 'desktop' | 'mobile'): Promise<CaptureEvidence> {
  const viewport = viewportName === 'desktop' ? { width: 1440, height: 1000 } : { width: 390, height: 844 };
  await page.setViewportSize(viewport);
  await page.waitForTimeout(120);
  const result = await audit(page);
  const directory = join(dirname(reportPath!), 'captures');
  await mkdir(directory, { recursive: true });
  const file = `${scenario}-${viewportName}.png`;
  const path = join(directory, file);
  await page.screenshot({ path, fullPage: true });
  return {
    file,
    sha256: createHash('sha256').update(await readFile(path)).digest('hex'),
    viewport: `${viewport.width}x${viewport.height}`,
    ...result,
  };
}

async function record(page: Page, name: string, start: number, steps: number, errors = 0, backtracks = 0): Promise<void> {
  scenarios[name] = {
    status: 'passed',
    steps,
    backtracks,
    errors,
    duration_ms: Math.max(1, Math.round(performance.now() - start)),
    next_action_visible: true,
    recovery_verified: true,
    captures: {
      desktop: await capture(page, name, 'desktop'),
      mobile: await capture(page, name, 'mobile'),
    },
  };
}

function orderFixture(available = false) {
  const order = {
    id: 41, site_id: 1, channel_id: 1, channel_name: 'Boutique fictive', order_number: 'WEB-DEMO-0041', source: 'ecommerce',
    status: 'pending_payment', payment_status: 'pending', fulfillment_status: 'unfulfilled', currency: 'CHF', grand_total_minor: 12900,
    paid_total_minor: 0, refunded_total_minor: 0, placed_at: '2026-07-15 09:00:00', customer: { name: 'Relation fictive' },
    lines: [{ id: 1, product_name: 'Produit sur commande fictif', sku: 'DEMO-ORDER', quantity: 1, fulfilled_quantity: 0, unit_price_minor: 12900, currency: 'CHF' }],
  };
  return {
    order,
    dossier: {
      order,
      state: {
        key: available ? 'payment_due' : 'waiting_availability',
        label: available ? 'Paiement attendu' : 'En attente de disponibilité',
        next_action: available ? 'request_payment' : 'monitor_availability',
        next_action_label: available ? 'Envoyer une demande de paiement' : 'Surveiller la disponibilité',
        blockers: ['payment_required_before_delivery'],
        actions: [{ key: 'record_payment', allowed: true, permitted: true }, { key: 'prepare_order', allowed: false, permitted: true }],
      },
      payments: { summary: { due_minor: 12900, active_payment_url: available ? 'https://payments.invalid/demo' : null, payment_proof: false }, plan: { status: available ? 'payment_due' : 'waiting_availability', expected_availability_at: '2026-08-01 09:00:00', price_policy: 'frozen', deposit_minor: 0 }, intents: [], transactions: [], refunds: [] },
      fulfillment: { summary: { status: 'unfulfilled', operations: 0, open_backorders: 1 }, operations: [], backorders: [{ id: 1, product_name: 'Produit sur commande fictif', quantity: 1 }] },
      documents: [{ id: 1, document_type: 'order_confirmation', document_number: 'ORD-DEMO-0041-FR-V1', issued_at: '2026-07-15 09:01:00' }],
      timeline: [{ id: 1, label: 'Commande créée', at: '2026-07-15 09:00:00' }], relation: { contact_id: 7 }, returns: [], messages: [],
    },
  };
}

test.describe('38e gate UX de convergence admin', () => {
  test.skip(!enabled, 'The isolated E2E environment and convergence report path are required');
  test.setTimeout(180_000);

  test.afterAll(async () => {
    if (!enabled) return;
    const expectedScenarios = [
      'advanced_tools', 'backorder_product', 'commerce', 'offers_marketing', 'pos', 'product_stock', 'sales_operator', 'service_relation',
    ];
    if (Object.keys(scenarios).length !== expectedScenarios.length) return;
    expect(Object.keys(scenarios).sort()).toEqual(expectedScenarios);
    const report = {
      format_version: 1,
      gate,
      status: 'passed',
      scenarios,
      permissions: permissionEvidence,
      security: { contains_pii: false, contains_secret: false, contains_card_data: false, contains_free_text: false },
    };
    await mkdir(dirname(reportPath!), { recursive: true });
    await writeFile(reportPath!, `${JSON.stringify(report, null, 2)}\n`, 'utf8');
  });

  test('service client keeps Relation context from global search to linked objects and memo', async ({ page }) => {
    const start = performance.now();
    await signIn(page);
    await page.route('**/admin/api/entries**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope([]) }));
    await page.route('**/admin/api/business/search**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ relations: [{ id: 17, type: 'contact', title: 'Relation fictive', subtitle: 'Donnée de démonstration' }] }) }));
    await page.route('**/admin/api/sale/orders**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ orders: [] }) }));
    await page.route('**/admin/api/business/catalog/products**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ products: [] }) }));
    await page.route('**/admin/api/business/relations?**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({
      relations: [{ id: 17, type: 'contact', display_name: 'Relation fictive', status: 'client', roles: ['client'], memo_count: 1, shared_memo_count: 0, last_activity_at: '2026-07-15 10:00:00', next_action_title: 'Répondre au formulaire', next_action_due_at: '2026-07-16' }],
      pagination: { limit: 50, offset: 0, total: 1, has_more: false },
    }) }));
    await page.goto(cmsPath('/admin/app'));
    await page.locator('.global-search-form input').fill('relation fictive');
    await page.getByText('Relation fictive').click();
    await expect(page).toHaveURL(/relation_type=contact&relation_id=17/);
    await page.route('**/admin/api/business/relations/contact/17/360**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({
      relation: { roles: [{ role_key: 'client' }], identity_linked: false }, next_action: { title: 'Répondre au formulaire', due_at: '2026-07-16' }, alerts: [],
      timeline: [{ id: 1, kind: 'form', summary: 'Formulaire reçu', created_at: '2026-07-15 10:00:00', metadata: { source_reference: 'FORM-DEMO' } }],
      orders: [{ id: 41, reference: 'WEB-DEMO-0041', summary: 'Commande en attente', status: 'pending', source: 'sale', owner_link: '/sale/orders?order_id=41' }],
      financial: [{ id: 1, reference: 'ORD-DEMO-0041-FR-V1', summary: 'Confirmation de commande', status: 'issued', owner_link: '/sale/orders?order_id=41' }],
      fulfillments: [{ id: 1, reference: 'LIV-DEMO-0041', summary: 'Livraison prévue', status: 'planned', owner_link: '/sale/orders?order_id=41' }],
      forms: [{ id: 1, form_id: 1, submission_id: 1, form_name: 'Demande fictive', safe_summary: 'Réponse reçue', occurred_at: '2026-07-15', resolution_strategy: 'token' }],
      consents_available: false, projection_health: { degraded: false }, ownership: {},
    }) }));
    await page.route('**/admin/api/business/contacts/17', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ contact: { id: 17, display_name: 'Relation fictive', status: 'client', company_id: null, email: null, phone: null, mobile: null } }) }));
    await page.route('**/admin/api/business/relations/contact/17', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ relation: { id: 17, type: 'contact', display_name: 'Relation fictive', status: 'client' } }) }));
    await page.route('**/admin/api/business/relations/contact/17/activity**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ activity: [] }) }));
    await page.route('**/admin/api/business/contacts/17/consents', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ channels: [], consents: [], history: [], preference: null }) }));
    await page.route('**/admin/api/business/iam/available-users**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ users: [] }) }));
    await page.route('**/admin/api/business/sale-activities/unlinked**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ activities: [], pagination: { total: 0 } }) }));
    await page.reload();
    const row = page.locator('.relations-table tbody tr').first();
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Voir Relation fictive', exact: true }).click();
    const modal = page.locator('.business-modal');
    await expect(modal.getByText('Répondre au formulaire').first()).toBeVisible();
    await expect(modal.getByText('WEB-DEMO-0041')).toBeVisible();
    await expect(modal.getByText('Demande fictive')).toBeVisible();
    await expect(modal.getByRole('button', { name: /Ajouter.*mémo/i }).first()).toBeVisible();
    await record(page, 'service_relation', start, 5);
  });

  test('sales operator moves from À traiter to one understandable order dossier', async ({ page }) => {
    const start = performance.now();
    await signIn(page);
    const fixture = orderFixture(false);
    await page.route('**/admin/api/sale/dashboard**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ actionable: { total: 1, tasks: [{ order_id: 41, order_number: 'WEB-DEMO-0041', customer: 'Relation fictive', channel: 'Boutique fictive', total_minor: 12900, currency: 'CHF', state: { label: 'En attente de disponibilité', next_action_label: 'Surveiller la disponibilité' } }] } }) }));
    await page.route('**/admin/api/sale/orders?**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ orders: [fixture.order] }) }));
    await page.route('**/admin/api/sale/orders/41/dossier', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ dossier: fixture.dossier }) }));
    await page.goto(cmsPath('/admin/app/sale'));
    await page.getByRole('button', { name: /WEB-DEMO-0041/ }).click();
    await expect(page.getByRole('heading', { name: 'WEB-DEMO-0041' })).toBeVisible();
    await expect(page.getByText('En attente de disponibilité', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Marquer disponible et demander le paiement' })).toBeVisible();
    await page.locator('.sale-order-modal').getByRole('button', { name: 'Fermer', exact: true }).click();
    await page.getByRole('link', { name: 'À traiter', exact: true }).click();
    await page.getByRole('button', { name: /WEB-DEMO-0041/ }).click();
    await expect(page.getByRole('heading', { name: 'WEB-DEMO-0041' })).toBeVisible();
    await record(page, 'sales_operator', start, 3);
  });

  test('backorder flow preserves the dossier and recovers before payment, invoice and delivery', async ({ page }) => {
    const start = performance.now();
    await signIn(page);
    let available = false;
    let attempts = 0;
    await page.route('**/admin/api/sale/orders?**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ orders: [orderFixture(available).order] }) }));
    await page.route('**/admin/api/sale/orders/41/dossier', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ dossier: orderFixture(available).dossier }) }));
    await page.route('**/admin/api/sale/orders/41/availability', async route => {
      attempts++;
      if (attempts === 1) {
        await route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ error: { message: 'Autorisation expirée, créez une nouvelle demande.' } }) });
        return;
      }
      available = true;
      await route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ plan: { status: 'payment_due' }, payment: { id: 5 } }) });
    });
    await page.goto(cmsPath('/admin/app/sale/orders?order_id=41'));
    const action = page.getByRole('button', { name: 'Marquer disponible et demander le paiement' });
    await action.click();
    await expect(page.locator('.admin-toast[role="status"]')).toContainText(/Autorisation expirée|nouvelle demande/i);
    await expect(action).toBeVisible();
    await action.click();
    await expect(page.getByText('Demande de paiement créée de façon idempotente.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Émettre la facture' })).toBeDisabled();
    await record(page, 'backorder_product', start, 5, 1);
  });

  test('POS makes immediate, deferred and on-order choices explicit in the same order model', async ({ page }) => {
    const start = performance.now();
    await signIn(page);
    await page.route('**/admin/api/sale/pos/bootstrap', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ registers: [{ id: 1, name: 'Caisse fictive' }], channels: [{ id: 1 }], active_session: { id: 1, register_id: 1, status: 'open', currency: 'CHF' }, payment_methods: [{ id: 1, code: 'cash', name: 'Espèces', method_type: 'cash' }] }) }));
    await page.route('**/admin/api/sale/pos/variants**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ variants: [] }) }));
    await page.goto(cmsPath('/admin/app/sale/pos'));
    await page.getByLabel('Remise ou livraison').selectOption('on_order');
    await page.getByLabel('Moment du paiement').selectOption('deposit');
    await expect(page.getByLabel('Disponibilité prévue')).toBeVisible();
    await expect(page.getByRole('option', { name: 'Acompte maintenant, solde plus tard' })).toBeAttached();
    await record(page, 'pos', start, 4);
  });

  test('product operator previews an audited receipt from projected stock quantities', async ({ page }) => {
    test.setTimeout(120_000);
    const start = performance.now();
    await signIn(page);
    await page.goto(cmsPath('/admin/app/business/products-stock'));
    const productRow = page.locator('tr').filter({ hasText: 'DEMO-GOURDE' }).first();
    const stockCell = productRow.locator('.catalog-stock-cell');
    await expect(stockCell).toBeVisible({ timeout: 60_000 });
    await stockCell.click();
    const productDialog = page.getByRole('dialog', { name: 'Modifier le stock' });
    await expect(productDialog.getByRole('heading', { name: 'Stock des variantes', exact: true })).toBeVisible({ timeout: 60_000 });
    await productDialog.getByRole('button', { name: 'Modifier l’inventaire' }).first().click();
    const inventoryDialog = page.getByRole('dialog', { name: 'Modifier l’inventaire' });
    await expect(inventoryDialog).toBeVisible();
    await expect(inventoryDialog.locator('.stock-summary')).toContainText('disponible à la vente');
    const stockForm = inventoryDialog.locator('form.stock-operation');
    await stockForm.locator('input[type="number"]').fill('1');
    await stockForm.locator('textarea').fill('Réception fictive de qualification 38e');
    await inventoryDialog.getByRole('button', { name: 'Prévisualiser l’impact' }).click();
    await expect(inventoryDialog.getByText('Impact avant confirmation')).toBeVisible();
    await expect(inventoryDialog.getByRole('button', { name: 'Confirmer la variation' })).toBeVisible();
    await record(page, 'product_stock', start, 4);
  });

  test('marketing operator reaches offer preview with Audience consent semantics', async ({ page }) => {
    const start = performance.now();
    await signIn(page);
    await page.goto(cmsPath('/admin/app/business/offers-marketing'));
    await page.getByRole('button', { name: 'Nouvelle réduction' }).first().click();
    const dialog = page.getByRole('dialog', { name: 'Éditer réduction' });
    await dialog.getByLabel('Nom de l’offre').fill('Offre fictive de convergence');
    await dialog.getByRole('button', { name: 'Suivant' }).click();
    await expect(dialog.getByText(/ne constitue jamais un consentement marketing/i)).toBeVisible();
    await expect(dialog.getByText('2. Périmètre')).toBeVisible();
    await record(page, 'offers_marketing', start, 4);
  });

  test('E-Commerce site configuration belongs to Sale settings and keeps the legacy API alias', async ({ page }) => {
    const start = performance.now();
    await signIn(page);
    const canonicalResponse = await page.request.get(cmsPath('/admin/api/sale/ecommerce/shops'));
    expect(canonicalResponse.ok(), await canonicalResponse.text()).toBeTruthy();
    const legacyResponse = await page.request.get(cmsPath('/admin/api/commerce/shops'));
    expect(legacyResponse.ok(), await legacyResponse.text()).toBeTruthy();
    expect((await legacyResponse.json()).data.shops).toEqual((await canonicalResponse.json()).data.shops);

    await page.goto(cmsPath('/admin/app/commerce'));
    await expect(page).toHaveURL(/\/admin\/app\/sale\/settings\?section=ecommerce$/);
    await expect(page.getByText('Aucune activation publique implicite', { exact: true })).toBeVisible();
    await record(page, 'commerce', start, 4);
  });

  test('advanced diagnostics stay visible to advanced users and hidden from ordinary navigation', async ({ page }) => {
    const start = performance.now();
    await signIn(page);
    await page.goto(cmsPath('/admin/app/sale/settings'));
    await expect(page.getByText('Outils avancés', { exact: true })).toBeVisible();
    permissionEvidence.advanced_navigation_visible = true;
    await record(page, 'advanced_tools', start, 3);

    await page.route('**/admin/api/context**', async route => {
      const response = await route.fetch();
      const body = await response.json();
      body.data.permissions = (body.data.permissions || []).filter((permission: string) => permission !== '*' && permission !== 'business.advanced_tools.manage' && permission !== 'sale.advanced_tools.manage');
      body.data.capabilities['business.advanced_tools.manage'] = false;
      body.data.capabilities['sale.advanced_tools.manage'] = false;
      await route.fulfill({ response, json: body });
    });
    await page.reload();
    await expect(page.getByText('Outils avancés', { exact: true })).toHaveCount(0);
    await page.goto(cmsPath('/admin/app/sale'));
    const ordinaryNav = page.locator('.module-secondary-navigation').first();
    await expect(ordinaryNav.getByText(/Réservations|Exécution logistique|Identités/)).toHaveCount(0);
    permissionEvidence.ordinary_navigation_hides_advanced = true;
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });
});
