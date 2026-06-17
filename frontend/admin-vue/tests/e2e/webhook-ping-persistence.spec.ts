import { test, expect } from '@playwright/test';

test.describe('webhook management', () => {
  test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_ADMIN_PASSWORD, 'E2E credentials are required');
  test('authorized user creates, pings and reloads a webhook delivery without exposing its secret', async ({ page }) => {
    await page.goto('/admin/app');
    await page.getByLabel('Email').fill(process.env.E2E_ADMIN_EMAIL!);
    await page.getByLabel('Mot de passe').fill(process.env.E2E_ADMIN_PASSWORD!);
    await page.getByRole('button', { name: /connexion/i }).click();
    await page.goto('/admin/app/security/webhooks');
    await page.getByRole('button', { name: /nouveau webhook/i }).click();
    await page.getByLabel(/nom/i).fill('E2E webhook');
    await page.getByLabel(/url/i).fill(process.env.E2E_WEBHOOK_URL ?? 'http://127.0.0.1:9876/webhook');
    await page.getByRole('button', { name: /enregistrer/i }).click();
    const row=page.getByRole('row', { name: /E2E webhook/i });
    await row.getByRole('button', { name: /ping/i }).click();
    await expect(row).toHaveAttribute('data-delivery-status', /succeeded|failed/);
    const deliveryId=await row.getAttribute('data-delivery-id');
    expect(deliveryId).toBeTruthy();
    await page.reload();
    await expect(page.locator(`[data-delivery-id="${deliveryId}"]`)).toBeVisible();
    await expect(page.locator('body')).not.toContainText(/0123456789abcdef|plain_secret/i);
  });

  test('direct API call without permission is forbidden', async ({ request }) => {
    const response=await request.post('/api/admin/security/webhooks/1/ping');
    expect([401,403]).toContain(response.status());
  });
});
