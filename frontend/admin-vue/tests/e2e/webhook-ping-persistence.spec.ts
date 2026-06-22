import { test, expect } from '@playwright/test';

const email = process.env.E2E_ADMIN_EMAIL;
const password = process.env.E2E_ADMIN_PASSWORD;

test.describe('webhook management', () => {
  test.skip(!email || !password, 'E2E credentials are required');

  test('authorized user creates, pings and reloads a webhook delivery without exposing its secret', async ({ page }) => {
    const webhookName = `E2E webhook ${Date.now()}`;

    await page.goto('/admin/login');
    await page.getByLabel('Email').fill(email!);
    await page.getByRole('button', { name: /continuer/i }).click();
    await page.locator('input[name="password"]').fill(password!);
    await page.getByRole('button', { name: /se connecter/i }).click();
    await expect(page).toHaveURL(/\/admin\/app(?:\/|$)/);

    await page.goto('/admin/app/settings');
    const webhooksTab = page.getByRole('button', { name: 'Webhooks', exact: true });
    await expect(webhooksTab).toBeVisible();
    await webhooksTab.click();
    const headlessHelp = page.locator('.amcms-headless-docs');
    await expect(headlessHelp.getByRole('link', { name: 'Documentation', exact: true })).toHaveCount(0);
    await expect(headlessHelp.getByRole('link', { name: 'OpenAPI v1 JSON', exact: true })).toHaveAttribute('href', /\/api\/v1\/openapi\.json$/);
    await expect(headlessHelp.getByRole('link', { name: 'OpenAPI v1 YAML', exact: true })).toHaveAttribute('href', /\/api\/v1\/openapi\.yaml$/);

    const createForm = page.locator('[data-webhook-form]');
    await expect(createForm).toBeVisible();
    await createForm.locator('input[name="name"]').fill(webhookName);
    await createForm.locator('input[name="url"]').fill(process.env.E2E_WEBHOOK_URL ?? 'http://127.0.0.1:9876/webhook');
    await createForm.getByRole('button', { name: 'Créer', exact: true }).click();

    let row = page.locator('.amcms-security-row').filter({ hasText: webhookName });
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Tester', exact: true }).click();
    const deliveryBox = row.locator('[data-webhook-deliveries]');
    await expect(deliveryBox).toBeVisible();
    await expect(deliveryBox).toContainText('webhook.ping');
    const deliveryText = await deliveryBox.textContent();
    const deliveryId = deliveryText?.match(/wh_[A-Za-z0-9_]+/)?.[0];
    expect(deliveryId).toBeTruthy();
    await expect(page.locator('body')).not.toContainText(/plain_secret|0123456789abcdef/i);

    await page.reload();
    await page.getByRole('button', { name: 'Webhooks', exact: true }).click();
    row = page.locator('.amcms-security-row').filter({ hasText: webhookName });
    await expect(row).toBeVisible();
    await row.getByRole('button', { name: 'Historique', exact: true }).click();
    await expect(row.locator('[data-webhook-deliveries]')).toContainText(deliveryId!);

    page.once('dialog', (dialog) => dialog.accept());
    await row.getByRole('button', { name: 'Supprimer', exact: true }).click();
    await expect(page.locator('.amcms-security-row').filter({ hasText: webhookName })).toHaveCount(0);

    await page.getByRole('button', { name: 'Relations', exact: true }).click();
    const corsHelp = page.locator('[data-amcms-cors-relations]');
    await expect(corsHelp).toBeVisible();
    await expect(corsHelp.getByRole('link', { name: 'Documentation', exact: true })).toHaveCount(0);
    await expect(corsHelp.getByRole('link', { name: 'OpenAPI v1 JSON', exact: true })).toHaveAttribute('href', /\/api\/v1\/openapi\.json$/);
    await expect(corsHelp.getByRole('link', { name: 'OpenAPI v1 YAML', exact: true })).toHaveAttribute('href', /\/api\/v1\/openapi\.yaml$/);
    await expect(corsHelp.getByRole('link', { name: /\/api\/v1$/, exact: true })).toHaveAttribute('href', /\/api\/v1$/);
  });

  test('direct API call without authentication is forbidden', async ({ request }) => {
    const response = await request.post('/admin/api/security/webhooks/1/ping');
    expect([401, 403]).toContain(response.status());
  });

  test('public API discovery and OpenAPI specifications are directly accessible', async ({ request }) => {
    const discovery = await request.get('/api/v1');
    expect(discovery.status()).toBe(200);
    const payload = await discovery.json();
    expect(payload.meta.contract).toBe('public.discovery.v1');
    expect(payload.data.openapi.json).toBe('/api/v1/openapi.json');
    expect(payload.data.openapi.yaml).toBe('/api/v1/openapi.yaml');

    const json = await request.get('/api/v1/openapi.json');
    expect(json.status()).toBe(200);
    expect(json.headers()['content-type']).toContain('application/json');
    expect((await json.json()).openapi).toBe('3.1.0');

    const yaml = await request.get('/api/v1/openapi.yaml');
    expect(yaml.status()).toBe(200);
    expect(yaml.headers()['content-type']).toContain('application/yaml');
    expect(await yaml.text()).toMatch(/openapi:\s+["']?3\.1\.0/);
  });
});
