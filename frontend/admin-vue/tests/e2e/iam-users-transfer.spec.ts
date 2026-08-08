import { readFile } from 'node:fs/promises';
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
  await page.getByRole('button', { name:/continuer/i }).click();
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name:/se connecter/i }).click();
  await expect(page).toHaveURL(/\/admin\/app(?:\/|$)/);
}

test.describe('IAM users import/export', () => {
  test.skip(!enabled, 'Dedicated E2E admin environment is required');

  test('exports no authentication secret and previews a safe merge import', async ({ page }) => {
    await signIn(page);
    await page.goto(cmsPath('/admin/app/iam/users'));
    await page.getByRole('button', { name:'Importer / exporter' }).click();
    await expect(page.getByRole('heading', { name:'Importer / exporter' })).toBeVisible();

    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('button', { name:'Exporter les utilisateurs' }).click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toMatch(/^utilisateurs-\d{4}-\d{2}-\d{2}\.json$/);
    const path = await download.path();
    expect(path).toBeTruthy();
    const content = await readFile(path!, 'utf8');
    const payload = JSON.parse(content) as { format:string; version:number; users:Array<Record<string,unknown>>; security:Record<string,boolean> };
    expect(payload.format).toBe('dec-cms-iam-users');
    expect(payload.version).toBe(1);
    expect(payload.users.length).toBeGreaterThan(0);
    for (const user of payload.users) {
      expect(user).not.toHaveProperty('password');
      expect(user).not.toHaveProperty('new_password');
      expect(user).not.toHaveProperty('password_hash');
      expect(user).not.toHaveProperty('sessions');
      expect(user).not.toHaveProperty('totp_secret');
    }
    expect(payload.security).toMatchObject({ passwords:false, password_hashes:false, sessions:false, totp_secrets:false });

    await page.getByLabel('Fichier JSON').setInputFiles({ name:'utilisateurs-test.json', mimeType:'application/json', buffer:Buffer.from(content) });
    await expect(page.getByText(/à mettre à jour/)).toBeVisible();
    await expect(page.getByText('0 erreur(s)', { exact:true })).toBeVisible();
    await expect(page.getByRole('button', { name:"Exécuter l’import" })).toBeEnabled();
  });
});
