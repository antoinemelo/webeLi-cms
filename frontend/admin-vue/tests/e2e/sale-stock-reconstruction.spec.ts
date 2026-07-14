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

const finding = {
  id: 'item:42:available_formula', inventory_item_id: 42, kind: 'available_formula', severity: 'critical',
  before: 7, after_preview: 8, impact: 'La fiche Shop publie un stock périmé.',
  probable_cause: 'Le cache disponible ne respecte plus physique moins réservé.',
  references: { links: { movements: '/admin/sale/stock?item_id=42', reservations: '/sale/reservations?item_id=42' } },
};

function diagnostic(mode = 'dry_run', remaining = 1) {
  return {
    run_id: mode === 'dry_run' ? 31 : 32, mode, status: remaining ? 'differences' : 'repaired', items_checked: 1,
    differences_count: 1, remaining_differences_count: remaining, critical_count: 1,
    differences: [finding], remaining_differences: remaining ? [finding] : [],
    shop_projection: { consistent: remaining === 0 }, backup: mode === 'repair' ? { path: '/safe/backups/m6-32' } : null,
  };
}

test.describe('M6.5 reconstruction et réconciliation stock', () => {
  test.skip(!enabled, 'Dedicated E2E admin environment is required');

  test('allows an administrator to diagnose and repair without database access', async ({ page }) => {
    await signIn(page);
    let diagnosticBody: unknown;
    let repairBody: unknown;
    await page.route('**/admin/api/sale/**', async (route) => {
      const request = route.request();
      const path = new URL(request.url()).pathname;
      const data = (() => {
        if (path.endsWith('/fulfillment/queue')) return { fulfillments: [] };
        if (path.endsWith('/stock/transfers')) return { transfers: [] };
        if (path.endsWith('/inventory-sessions')) return { sessions: [] };
        if (path.endsWith('/stock/items')) return { items: [], locations: [] };
        if (path.endsWith('/orders')) return { orders: [] };
        if (path.endsWith('/stock/reconciliation/repair')) {
          repairBody = request.postDataJSON();
          return { reconciliation: diagnostic('repair', 0) };
        }
        if (path.endsWith('/stock/reconciliation') && request.method() === 'POST') {
          diagnosticBody = request.postDataJSON();
          return { reconciliation: diagnostic() };
        }
        if (path.endsWith('/stock/reconciliation')) return { runs: [] };
        return {};
      })();
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data }) });
    });

    await page.goto(cmsPath('/admin/app/sale/operations'));
    await page.getByRole('button', { name: 'Inventaires' }).click();
    await page.getByRole('button', { name: 'Lancer l’aperçu' }).click();
    await expect(page.getByText('available_formula · item #42')).toBeVisible();
    await expect(page.getByText('Avant 7 → Après 8')).toBeVisible();
    await expect(page.getByText('La fiche Shop publie un stock périmé.')).toBeVisible();
    expect(diagnosticBody).toEqual({ data: { only_inventory_item_ids: [] } });

    page.once('dialog', dialog => dialog.accept('Comptage opérateur vérifié'));
    await page.getByRole('button', { name: 'Réparer après sauvegarde' }).click();
    await expect(page.getByText(/Réparation terminée\. Sauvegarde : \/safe\/backups\/m6-32/)).toBeVisible();
    expect(repairBody).toMatchObject({ data: { reason: 'Comptage opérateur vérifié', corrections: [] } });
    await expect(page.getByText('cohérent')).toBeVisible();

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByRole('heading', { name: 'Diagnostic de reconstruction' })).toBeVisible();
  });
});
