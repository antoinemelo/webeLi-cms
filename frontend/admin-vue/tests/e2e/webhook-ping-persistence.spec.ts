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
  });

  test('direct API call without authentication is forbidden', async ({ request }) => {
    const response = await request.post('/admin/api/security/webhooks/1/ping');
    expect([401, 403]).toContain(response.status());
  });
});
