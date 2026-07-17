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

test.describe('38c dossier Commande, paiement, livraison et POS', () => {
  test.skip(!enabled, 'Dedicated E2E admin environment is required');

  test('guides a deferred order without early invoice on desktop, mobile and FR/EN', async ({ page }) => {
    test.setTimeout(120_000);
    await page.addInitScript(() => {
      Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: { writeText: async (value: string) => { (window as unknown as { __copiedCustomer?: string }).__copiedCustomer = value; } },
      });
    });
    await signIn(page);
    let available = false;
    const order = {
      id: 41, site_id: 1, channel_id: 1, channel_name: 'Boutique web', order_number: 'WEB-0041', source: 'ecommerce',
      status: 'pending_payment', payment_status: 'pending', fulfillment_status: 'unfulfilled', currency: 'CHF',
      grand_total_minor: 12900, paid_total_minor: 0, refunded_total_minor: 0, placed_at: '2026-07-15 09:00:00',
      customer_contact_id: 7, customer_company_id: 8, customer: { first_name: 'Ada', last_name: 'Exemple', display_name: 'Ada Exemple', company_name: 'Exemple SA' }, shipping_method: { type: 'shipping' },
      lines: [{ id: 1, product_name: 'Produit sur commande', sku: 'ON-ORDER', quantity: 1, fulfilled_quantity: 0, unit_price_minor: 12900, currency: 'CHF' }],
    };
    const dossier = () => ({
      order,
      state: {
        key: available ? 'payment_due' : 'waiting_availability',
        label: available ? 'Paiement attendu' : 'En attente de disponibilité',
        next_action: available ? 'request_payment' : 'monitor_availability',
        next_action_label: available ? 'Envoyer une demande de paiement' : 'Surveiller la disponibilité',
        blockers: ['payment_required_before_delivery'],
        actions: [
          { key: 'record_payment', allowed: true, permitted: true },
          { key: 'prepare_order', allowed: false, permitted: true },
          { key: 'cancel_order', allowed: true, permitted: true },
        ],
      },
      payments: {
        summary: { due_minor: 12900, active_payment_url: available ? 'https://payments.example.test/41' : null, payment_proof: false },
        plan: { status: available ? 'payment_due' : 'waiting_availability', expected_availability_at: '2026-08-01 09:00:00', price_policy: 'frozen', deposit_minor: 0 },
        intents: [], transactions: [], refunds: [],
      },
      fulfillment: { summary: { status: 'unfulfilled', operations: 0, open_backorders: 1 }, operations: [], backorders: [{ id: 1, product_name: 'Produit sur commande', quantity: 1 }] },
      documents: [{ id: 1, document_type: 'order_confirmation', document_number: 'ORD-WEB-0041-FR-V1', issued_at: '2026-07-15 09:01:00', printable_text: 'Confirmation de commande WEB-0041\nTotal : 129.00 CHF' }],
      timeline: [{ id: 1, label: 'Commande créée', at: '2026-07-15 09:00:00' }], relation: { contact_id: 7 }, returns: [], messages: [],
    });

    await page.route('**/admin/api/sale/orders?**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ orders: [order] }) }));
    await page.route('**/admin/api/sale/orders/41/dossier', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ dossier: dossier() }) }));
    await page.route('**/admin/api/sale/orders/41/availability', async route => {
      available = true;
      await route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ plan: { status: 'payment_due' }, payment: { id: 5 } }) });
    });

    await page.goto(cmsPath('/admin/app/sale/orders'));
    await expect(page.locator('.sale-order-dossier')).toHaveCount(0);
    const quickFilters = page.getByRole('navigation', { name: 'Filtres rapides commandes' });
    await expect(quickFilters.getByRole('button')).toHaveCount(8);
    await quickFilters.getByRole('button', { name: 'POS', exact: true }).click();
    await expect(page.getByText('Aucune commande ne correspond aux filtres.')).toBeVisible();
    const removePosFilter = page.getByRole('button', { name: 'Retirer le filtre Source · POS' });
    await expect(removePosFilter).toBeVisible();
    await removePosFilter.click();
    await expect(page.getByRole('button', { name: 'Voir', exact: true })).toBeVisible();
    await quickFilters.getByRole('button', { name: 'E-commerce', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Retirer le filtre Source · E-commerce' })).toBeVisible();
    await quickFilters.getByRole('button', { name: 'Toutes', exact: true }).click();
    await expect(page.locator('.sale-filter-chip')).toHaveCount(0);
    await quickFilters.getByRole('button', { name: 'En attente', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Retirer le filtre Paiement · En attente' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Voir la commande WEB-0041' })).toBeVisible();
    await quickFilters.getByRole('button', { name: 'Toutes', exact: true }).click();
    await page.getByRole('button', { name: 'Copier la relation Ada Exemple / Exemple SA' }).click();
    expect(await page.evaluate(() => (window as unknown as { __copiedCustomer?: string }).__copiedCustomer)).toBe('Ada Exemple / Exemple SA');
    await expect(page.getByText('Relation copiée.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Voir la commande WEB-0041' }).click();
    await expect(page.getByRole('heading', { name: 'WEB-0041' })).toBeVisible();
    await expect(page.getByText('Ada Exemple', { exact: true })).toBeVisible();
    await expect(page.locator('.sale-order-dossier').getByText('Exemple SA', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Ouvrir la relation client' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Copier le nom du client' }).click();
    expect(await page.evaluate(() => (window as unknown as { __copiedCustomer?: string }).__copiedCustomer)).toBe('Ada Exemple');
    await expect(page.getByText('Nom du client copié.', { exact: true })).toBeVisible();
    await expect(page.getByText('En attente de disponibilité', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Émettre la facture' })).toBeDisabled();
    const availableButton = page.getByRole('button', { name: 'Marquer disponible et demander le paiement' });
    await availableButton.focus();
    await expect(availableButton).toBeFocused();
    await availableButton.click();
    await expect(page.getByText('Demande de paiement créée de façon idempotente.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Ouvrir le lien de paiement' })).toBeVisible();
    await page.locator('.sale-order-dossier__document-row').getByRole('button', { name: 'Voir' }).click();
    await expect(page.getByText('Confirmation de commande WEB-0041')).toBeVisible();
    await page.locator('.sale-document-modal').getByRole('button', { name: 'Fermer', exact: true }).last().click();

    await page.setViewportSize({ width: 390, height: 820 });
    await expect(page.locator('.sale-order-dossier')).toBeVisible();
    await expect(page.getByText('Historique unifié')).toBeVisible();

    available = false;
    await page.evaluate(() => localStorage.setItem('amcms.admin.uiLanguage', 'en'));
    await page.reload();
    await expect(page.getByText('Payment deferred until availability')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Mark available and request payment' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Issue invoice' })).toBeDisabled();
  });

  test('makes immediate, delayed, delivery and on-order POS choices explicit', async ({ page }) => {
    await signIn(page);
    await page.route('**/admin/api/sale/pos/bootstrap', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({
      registers: [{ id: 1, name: 'Caisse principale' }], channels: [{ id: 1 }],
      active_session: { id: 1, register_id: 1, status: 'open', currency: 'CHF', expected_cash_minor: 10000 },
      payment_methods: [{ id: 1, code: 'cash', name: 'Cash', method_type: 'cash' }],
      fulfillment_methods: [{ id: 1, code: 'standard', label: 'Livraison standard', fulfillment_type: 'shipping' }],
    }) }));
    await page.route('**/admin/api/sale/pos/variants**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ variants: [] }) }));
    await page.route('**/admin/api/business/contacts**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({
      contacts: [{ id: 77, company_id: 88, display_name: 'Ada Exemple', email: 'ada@example.test', company_name: 'Exemple SA' }],
    }) }));
    await page.route('**/admin/api/sale/pos/sessions/1/close', async route => {
      const payload = route.request().postDataJSON() as { data?: { counted_cash_minor?: number; difference_justification?: string } };
      expect(payload.data).toMatchObject({ counted_cash_minor: 9999, difference_justification: 'Écart de comptage' });
      await route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ session: { id: 1, register_id: 1, status: 'closed', currency: 'CHF', expected_cash_minor: 10000, counted_cash_minor: 9999, difference_minor: -1 } }) });
    });
    await page.goto(cmsPath('/admin/app/sale/pos'));
    await expect(page.getByText('Client CRM', { exact: true })).toHaveCount(0);
    await expect(page.getByText('Facultatif : la vente restera anonyme sans client sélectionné.', { exact: true })).toHaveCount(0);
    await page.getByLabel('Rechercher un client (facultatif)…').fill('Ada');
    await page.getByRole('button', { name: 'Ada Exemple' }).click();
    await expect(page.getByText('Ada Exemple · Exemple SA')).toBeVisible();
    await expect(page.getByLabel('Remise ou livraison')).toBeVisible();
    await expect(page.getByLabel('Moment du paiement')).toBeVisible();
    await page.getByLabel('Remise ou livraison').selectOption('delivery_later');
    await expect(page.getByLabel('Mode de livraison')).toHaveValue('standard');
    await expect(page.getByRole('option', { name: 'Livraison standard' })).toBeAttached();
    await page.getByLabel('Remise ou livraison').selectOption('on_order');
    await expect(page.getByLabel('Disponibilité prévue')).toBeVisible();
    await page.getByLabel('Moment du paiement').selectOption('deposit');
    await expect(page.getByRole('option', { name: 'Acompte maintenant, solde plus tard' })).toBeAttached();
    await expect(page.getByLabel('Justification si écart')).toHaveCount(0);
    await page.getByLabel('Cash compté').fill('99.99');
    const closeRegister = page.getByRole('button', { name: 'Fermer', exact: true });
    await expect(page.getByLabel('Justification si écart')).toBeVisible();
    await expect(closeRegister).toBeDisabled();
    await page.getByLabel('Justification si écart').fill('Écart de comptage');
    await expect(closeRegister).toBeEnabled();
    await closeRegister.click();
    await expect(page.getByText('Session caisse fermée.')).toBeVisible();
  });

  test('opens and reopens the selected suspended order from À traiter', async ({ page }) => {
    await signIn(page);
    const order = {
      id: 41, order_number: 'WEB-SUSPEND-0041', source: 'ecommerce', status: 'pending_payment', payment_status: 'pending',
      fulfillment_status: 'unfulfilled', currency: 'CHF', grand_total_minor: 12900, paid_total_minor: 0,
      placed_at: '2026-07-15 09:00:00', customer: { display_name: 'Ada Exemple' }, lines: [],
    };
    const dossier = {
      order,
      state: { key: 'waiting_availability', label: 'En attente de disponibilité', next_action: 'monitor_availability', next_action_label: 'Surveiller la disponibilité', blockers: ['stock_unavailable'], actions: [] },
      payments: { summary: { due_minor: 12900 }, plan: { status: 'waiting_availability' }, intents: [], transactions: [], refunds: [] },
      fulfillment: { summary: { status: 'unfulfilled' }, operations: [], backorders: [] },
      documents: [], timeline: [], relation: {}, returns: [], messages: [],
    };
    await page.route('**/admin/api/sale/dashboard', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ actionable: { total: 1, tasks: [{ order_id: 41, order_number: 'WEB-SUSPEND-0041', customer: 'Ada Exemple', channel: 'Boutique web', total_minor: 12900, currency: 'CHF', state: dossier.state }] } }) }));
    await page.route('**/admin/api/sale/orders?**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ orders: [order] }) }));
    await page.route('**/admin/api/sale/orders/41/dossier', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ dossier }) }));

    await page.goto(cmsPath('/admin/app/sale'));
    await page.getByRole('button', { name: /WEB-SUSPEND-0041/ }).click();
    await expect(page).toHaveURL(/\/admin\/app\/sale\/orders\?order_id=41$/);
    const orderDialog = page.locator('.sale-order-modal');
    await expect(orderDialog.getByRole('heading', { name: 'WEB-SUSPEND-0041' })).toBeVisible();
    await orderDialog.getByRole('button', { name: 'Fermer', exact: true }).click();
    await page.getByRole('link', { name: 'À traiter', exact: true }).click();
    await page.getByRole('button', { name: /WEB-SUSPEND-0041/ }).click();
    await expect(orderDialog.getByRole('heading', { name: 'WEB-SUSPEND-0041' })).toBeVisible();
  });

  test('closes a fulfilled order from its next action and refreshes the dossier', async ({ page }) => {
    await page.addInitScript(() => {
      Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: { writeText: async (value: string) => { (window as unknown as { __copiedCustomer?: string }).__copiedCustomer = value; } },
      });
    });
    await signIn(page);
    let completed = false;
    const order = () => ({
      id: 77, site_id: 1, channel_id: 1, channel_name: 'Boutique web', order_number: 'SALE-READY-77', source: 'ecommerce',
      status: completed ? 'completed' : 'confirmed', payment_status: 'paid', fulfillment_status: 'fulfilled', currency: 'CHF',
      subtotal_minor: 2900, shipping_total_minor: 0, tax_total_minor: 235, grand_total_minor: 2900, paid_total_minor: 2900,
      customer_company_id: 88, customer: { company_name: 'Exemple SA' },
      placed_at: '2026-07-15 09:00:00', lines: [{ id: 1, product_name: 'Gourde', sku: 'GOURDE', quantity: 1, unit_price_minor: 2900, currency: 'CHF' }],
    });
    const dossier = () => ({
      order: order(),
      state: {
        key: completed ? 'completed' : 'ready_to_close',
        label: completed ? 'Terminée' : 'À clôturer',
        next_action: completed ? 'none' : 'close_order',
        next_action_label: completed ? 'Aucune action' : 'Clôturer la commande',
        blockers: [],
        actions: [{ key: 'close_order', allowed: !completed, permitted: true }],
      },
      payments: { summary: { due_minor: 0 }, plan: null, intents: [], transactions: [], refunds: [] },
      fulfillment: { summary: { status: 'fulfilled' }, operations: [], backorders: [] },
      documents: [], timeline: [], relation: {}, returns: [], messages: [],
    });

    await page.route('**/admin/api/sale/orders?**', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ orders: [order()] }) }));
    await page.route('**/admin/api/sale/orders/77/dossier', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ dossier: dossier() }) }));
    await page.route('**/admin/api/sale/orders/77', async route => {
      expect(route.request().method()).toBe('PATCH');
      expect((await route.request().postDataJSON()).data.status).toBe('completed');
      completed = true;
      await route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ order: order() }) });
    });
    await page.route('**/admin/api/sale/dashboard', route => route.fulfill({ status: 200, contentType: 'application/json', body: envelope({ actionable: { tasks: [], groups: {}, total: 0 } }) }));

    await page.goto(cmsPath('/admin/app/sale/orders'));
    await page.getByRole('button', { name: 'Voir', exact: true }).click();
    await expect(page.getByText('À clôturer', { exact: true })).toBeVisible();
    await expect(page.locator('.sale-order-dossier').getByText('Exemple SA', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Copier le nom du client' }).click();
    expect(await page.evaluate(() => (window as unknown as { __copiedCustomer?: string }).__copiedCustomer)).toBe('Exemple SA');
    page.once('dialog', dialog => dialog.accept());
    await page.getByRole('button', { name: 'Clôturer la commande', exact: true }).click();
    await expect(page.getByText('Commande clôturée.', { exact: true })).toBeVisible();
    await expect(page.getByRole('dialog').getByText('Terminée', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Clôturer la commande', exact: true })).toHaveCount(0);
  });
});
