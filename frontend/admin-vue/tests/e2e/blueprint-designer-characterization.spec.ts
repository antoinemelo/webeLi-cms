import { test, expect, type Locator, type Page } from '@playwright/test';

const baseUrl = process.env.E2E_BASE_URL;
const email = process.env.E2E_ADMIN_EMAIL;
const password = process.env.E2E_ADMIN_PASSWORD;
const hasDedicatedEnvironment = Boolean(baseUrl && email && password);

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

async function findFieldFixture(page: Page, pattern: RegExp): Promise<Locator | null> {
  const fields = page.locator('.blueprint-tree__field').filter({ hasText: pattern });
  if (await fields.count() > 0) return fields.first();
  const models = page.locator('.blueprint-column--models .list-group-item-action');
  const count = Math.min(await models.count(), 12);
  for (let index = 0; index < count; index += 1) {
    const model = models.nth(index);
    if (!await model.isVisible()) continue;
    const designResponse = page.waitForResponse((response) => response.request().method() === 'GET' && /\/admin\/api\/blueprints\/[^/]+\/design/.test(response.url()), { timeout: 5000 }).catch(() => null);
    await model.click();
    await designResponse;
    if (await fields.count() > 0) return fields.first();
  }
  return null;
}

test.describe('blueprint designer safe activation', () => {
  test.skip(!hasDedicatedEnvironment, 'Dedicated E2E_BASE_URL, E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD are required');

  test.beforeEach(async ({ page }) => {
    await signIn(page);
    const designResponse = page.waitForResponse((response) => response.request().method() === 'GET' && /\/admin\/api\/blueprints\/[^/]+\/design/.test(response.url()), { timeout: 3000 }).catch(() => null);
    await page.goto(cmsPath('/admin/app/blueprints'));
    await expect(page.getByRole('heading', { name: 'Structures de contenu' })).toBeVisible();
    const response = await designResponse;
    if (response) {
      expect(response.ok()).toBeTruthy();
      const payload = await response.json();
      await expect(page.getByLabel('Nom affiché', { exact: true }).first()).toHaveValue(payload.data.blueprint.label);
    } else {
      await expect(page.locator('.blueprint-column--models .list-group-item-action.active').first()).toBeVisible();
      await expect(page.getByLabel('Nom affiché', { exact: true }).first()).toBeEnabled();
    }
  });

  test('routes through the configured CMS prefix', async ({ page }) => {
    const expectedPrefix = new URL(baseUrl!).pathname.replace(/\/+$/, '');
    expect(page.url()).toContain(`${expectedPrefix}/admin/app/blueprints`);
  });

  test('tracks local changes and warns before selecting another blueprint', async ({ page }) => {
    const models = page.locator('.blueprint-column--models .list-group-item-action');
    test.skip(await models.count() < 2, 'At least two blueprint fixtures are required');

    let initialIndex = 0;
    for (let index = 0; index < await models.count(); index += 1) {
      if ((await models.nth(index).getAttribute('class'))?.includes('active')) { initialIndex = index; break; }
    }
    const first = models.nth(initialIndex);
    const second = models.nth(initialIndex === 0 ? 1 : 0);
    await expect(first).toBeVisible();
    const label = page.getByLabel('Nom affiché', { exact: true }).first();
    const original = await label.inputValue();
    await label.fill(`${original} — modification locale E2E`);
    await expect(page.getByText('Modifications locales non enregistrées').first()).toBeVisible();

    page.once('dialog', (dialog) => dialog.dismiss());
    await second.click();
    await expect(label).toHaveValue(`${original} — modification locale E2E`);

    page.once('dialog', (dialog) => dialog.accept());
    const secondDesign = page.waitForResponse((response) => response.request().method() === 'GET' && /\/admin\/api\/blueprints\/[^/]+\/design/.test(response.url()));
    await second.click();
    await secondDesign;
    await expect(second).toHaveClass(/active/);
    const firstDesign = page.waitForResponse((response) => response.request().method() === 'GET' && /\/admin\/api\/blueprints\/[^/]+\/design/.test(response.url()));
    await first.click();
    await firstDesign;
    await expect(first).toHaveClass(/active/);
    await expect(label).toHaveValue(original);
  });

  test('cancels a field modal without mutating the local design', async ({ page }) => {
    let implicitWrites = 0;
    page.on('request', (request) => {
      if (['PUT', 'POST'].includes(request.method()) && /\/admin\/api\/(?:blueprints|fieldsets)/.test(request.url())) implicitWrites += 1;
    });
    const fields = page.locator('.blueprint-tree__field-main');
    test.skip(await fields.count() === 0, 'At least one editable blueprint field is required');

    await fields.first().click();
    const modal = page.getByRole('dialog', { name: 'Modifier le champ' });
    const label = modal.getByLabel('Nom affiché', { exact: true });
    const original = await label.inputValue();
    await label.fill(`${original} — annulé`);
    await modal.getByRole('button', { name: 'Annuler', exact: true }).click();

    await fields.first().click();
    await expect(page.getByRole('dialog', { name: 'Modifier le champ' }).getByLabel('Nom affiché', { exact: true })).toHaveValue(original);
    await page.getByRole('dialog', { name: 'Modifier le champ' }).getByRole('button', { name: 'Annuler', exact: true }).click();
    await page.waitForTimeout(600);
    expect(implicitWrites).toBe(0);
  });

  test('keeps the draft after a failed activation and blocks double submission', async ({ page }) => {
    let postCount = 0;
    let releaseResponse = (): void => {};
    const responseGate = new Promise<void>((resolve) => { releaseResponse = resolve; });
    await page.route(/\/admin\/api\/blueprints\/[^/]+\/activate(?:\?|$)/, async (route) => {
      if (route.request().method() !== 'POST') return route.continue();
      postCount += 1;
      await responseGate;
      await route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ error: { code: 'E2E_FAILURE', message: 'Échec simulé' }, meta: { contract: 'error.v1' } })
      });
    });

    const label = page.getByLabel('Nom affiché', { exact: true }).first();
    const changed = `${await label.inputValue()} — échec E2E`;
    await label.fill(changed);
    const saveDraft = page.getByRole('button', { name: /Enregistrer le brouillon|Enregistrement/ });
    page.once('dialog', (dialog) => {
      expect(dialog.message()).toContain(changed.replace(' — échec E2E', ''));
      expect(dialog.message()).toContain('La version active ne sera pas modifiée.');
      void dialog.accept();
    });
    const saveResponse = page.waitForResponse((response) => response.request().method() === 'PUT' && /\/admin\/api\/blueprints\/[^/]+\/design/.test(response.url()));
    await saveDraft.click();
    expect((await saveResponse).ok()).toBeTruthy();
    await expect(page.getByText('Brouillon enregistré')).toBeVisible();
    await expect(page.getByText('Aucune modification locale').first()).toBeVisible();

    const activate = page.getByRole('button', { name: /Activer le brouillon|Activation/ });
    page.once('dialog', (dialog) => {
      expect(dialog.message()).toContain('Version active actuelle');
      expect(dialog.message()).toContain('Brouillon à activer');
      void dialog.accept();
    });
    const activation = activate.click();
    try {
      await expect.poll(() => postCount).toBe(1);
      await expect(activate).toBeDisabled();
      await activate.evaluate((button: HTMLButtonElement) => button.click());
    } finally {
      releaseResponse();
    }
    await activation;
    await expect(page.getByText('Échec simulé')).toBeVisible();
    expect(postCount).toBe(1);
    await expect(label).toHaveValue(changed);
    await expect(page.getByText('Aucune modification locale').first()).toBeVisible();
  });

  test('filters structures and hides system elements by default', async ({ page }) => {
    await expect(page.getByLabel('Afficher les éléments système')).not.toBeChecked();
    await page.getByPlaceholder('Rechercher une structure…').fill('valeur sans résultat e2e');
    await expect(page.getByText('Aucune structure ne correspond aux filtres.')).toBeVisible();
    await page.getByPlaceholder('Rechercher une structure…').fill('');
    await page.getByLabel('Portée').selectOption('global');
    await expect(page.locator('.blueprint-column--models .list-group-item-action').first()).toContainText('Globale');
  });

  test('uses the explicit scope when identical global and local keys coexist', async ({ page }) => {
    const buttons = page.locator('.blueprint-column--models .list-group-item-action');
    const keys = await buttons.locator('.font-monospace').allTextContents();
    const duplicate = keys.find((key, index) => keys.indexOf(key) !== index);
    test.skip(!duplicate, 'A global/local fixture sharing one key is required');

    const twins = buttons.filter({ hasText: duplicate! });
    const global = twins.filter({ hasText: 'Globale' });
    const local = twins.filter({ hasText: 'Propre à' });
    if ((await global.getAttribute('class'))?.includes('active')) {
      const initialLocalRequest = page.waitForRequest((request) => request.url().includes('/design') && request.url().includes('scope=site'));
      await local.click();
      await initialLocalRequest;
    }
    const globalRequest = page.waitForRequest((request) => request.url().includes('/design') && request.url().includes('scope=global'));
    await global.click(); await globalRequest;
    const localRequest = page.waitForRequest((request) => request.url().includes('/design') && request.url().includes('scope=site'));
    await local.click();
    await localRequest;
  });

  test('warns before changing site with local changes', async ({ page }) => {
    const site = page.getByLabel('Changer de site administré');
    test.skip(await site.count() === 0 || (await site.locator('option').count()) < 2, 'At least two authorized sites are required');
    const initial = await site.inputValue();
    const target = await site.locator('option').evaluateAll((options, current) => (options as HTMLOptionElement[]).find((option) => option.value !== current)?.value, initial);
    const initialUrl = page.url();
    await page.getByLabel('Nom affiché', { exact: true }).first().fill('Modification locale multisite E2E');
    page.once('dialog', (dialog) => dialog.dismiss());
    await site.selectOption(target!);
    await expect(page).toHaveURL(initialUrl);
  });

  test('keeps all mutation controls disabled for a read-only user', async ({ page }) => {
    const contextResponse = await page.request.get(cmsPath('/admin/api/context'));
    expect(contextResponse.ok()).toBeTruthy();
    const body = await contextResponse.json();
    await page.route('**/admin/api/context**', async (route) => {
      body.data.capabilities['blueprints.manage'] = false;
      body.data.permissions = body.data.permissions.filter((permission: string) => permission !== 'blueprints.manage' && permission !== '*');
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });
    });
    await page.reload();
    await expect(page.getByRole('button', { name: 'Créer' }).first()).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Enregistrer le brouillon' })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Activer le brouillon' })).toBeDisabled();
    await expect(page.getByLabel('Nom affiché', { exact: true }).first()).toBeDisabled();
    await page.unrouteAll({ behavior: 'wait' });
  });

  test('supports keyboard-safe reordering without implicit persistence', async ({ page }) => {
    const sections = page.locator('.blueprint-tree__section');
    let fields = sections.first().locator('.blueprint-tree__field');
    for (let index = 0; index < await sections.count(); index += 1) {
      const candidate = sections.nth(index).locator('.blueprint-tree__field');
      if (await candidate.count() >= 2) { fields = candidate; break; }
    }
    test.skip(await fields.count() < 2, 'At least two fields in one section are required');
    const firstHandle = await fields.nth(0).locator('code').textContent();
    const second = fields.nth(1);
    const secondHandle = await second.locator('code').textContent();
    await second.getByRole('button', { name: /^Monter / }).click();
    await expect(fields.nth(0).locator('code')).toHaveText(secondHandle || '');
    await expect(fields.nth(1).locator('code')).toHaveText(firstHandle || '');
    await expect(page.getByText('Modifications locales non enregistrées').first()).toBeVisible();
  });

  test('focuses dialogs, closes them with Escape and restores focus', async ({ page }) => {
    const trigger = page.locator('.blueprint-tree__field-main').first();
    test.skip(await trigger.count() === 0, 'At least one editable field is required');
    await trigger.focus();
    await trigger.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Modifier le champ' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Nom affiché')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
  });

  test('navigates editor tabs with arrow keys', async ({ page }) => {
    const structures = page.getByRole('tab', { name: 'Structures de contenu' });
    const fieldsets = page.getByRole('tab', { name: 'Groupes de champs réutilisables' });
    await structures.focus();
    await structures.press('ArrowRight');
    await expect(fieldsets).toBeFocused();
    await expect(fieldsets).toHaveAttribute('aria-selected', 'true');
    await fieldsets.press('ArrowLeft');
    await expect(structures).toBeFocused();
  });

  test('uses a list-to-detail workflow on a narrow screen', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByRole('navigation', { name: 'Étapes de l’éditeur' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Créer' }).first()).toBeVisible();
    await page.getByRole('button', { name: 'Structure', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Sections, champs et groupes réutilisables' })).toBeVisible();
    await page.getByRole('button', { name: 'Inspecteur', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Propriétés de la section' })).toBeVisible();
  });

  test('blocks invalid expert JSON and preserves unknown option keys locally', async ({ page }) => {
    const trigger = page.locator('.blueprint-tree__field-main').first();
    test.skip(await trigger.count() === 0, 'At least one blueprint field is required');
    await trigger.click();
    const dialog = page.getByRole('dialog', { name: 'Modifier le champ' });
    await dialog.getByText('Options expertes').click();
    const optionsJson = dialog.getByLabel('Options JSON');
    await optionsJson.fill('{ invalid');
    await expect(dialog.getByText('JSON invalide dans options.')).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Appliquer localement' })).toBeDisabled();
    await dialog.getByRole('button', { name: 'Annuler', exact: true }).click();

    await trigger.click();
    await page.getByRole('dialog', { name: 'Modifier le champ' }).getByText('Options expertes').click();
    await expect(page.getByRole('dialog', { name: 'Modifier le champ' }).getByLabel('Options JSON')).not.toHaveValue('{ invalid');
    await page.getByRole('dialog', { name: 'Modifier le champ' }).getByLabel('Options JSON').fill('{"unknown_e2e":{"nested":true}}');
    await page.getByRole('dialog', { name: 'Modifier le champ' }).getByRole('button', { name: 'Appliquer localement' }).click();

    await trigger.click();
    const reopened = page.getByRole('dialog', { name: 'Modifier le champ' });
    await reopened.getByText('Options expertes').click();
    await expect(reopened.getByLabel('Options JSON')).toHaveValue(/unknown_e2e/);
    await reopened.getByRole('button', { name: 'Annuler', exact: true }).click();
  });

  test('edits choice options through guided controls and the shared JSON model', async ({ page }) => {
    const choiceField = await findFieldFixture(page, /select|multiselect|radio|checkboxes|button_group/);
    test.skip(!choiceField, 'A choice field fixture is required');
    if (!choiceField) return;
    await choiceField.locator('.blueprint-tree__field-main').click();
    const dialog = page.getByRole('dialog', { name: 'Modifier le champ' });
    await expect(dialog.getByRole('heading', { name: 'Configuration guidée' })).toBeVisible();
    const valueInputs = dialog.getByLabel('Valeur');
    const labelInputs = dialog.getByLabel('Libellé');
    const optionCount = await valueInputs.count();
    await dialog.getByRole('button', { name: 'Ajouter une option' }).click();
    await expect(valueInputs).toHaveCount(optionCount + 1);
    await valueInputs.nth(optionCount).fill('e2e_choice');
    await labelInputs.nth(optionCount).fill('Choix E2E');
    await dialog.getByText('Options expertes').click();
    await expect(dialog.getByLabel('Options JSON')).toHaveValue(/e2e_choice/);
    await expect(dialog.getByLabel('Options JSON')).toHaveValue(/Choix E2E/);
    await dialog.getByRole('button', { name: 'Annuler', exact: true }).click();
  });

  test('edits supported media validation without touching unknown expert keys', async ({ page }) => {
    const mediaField = await findFieldFixture(page, /media|assets/);
    test.skip(!mediaField, 'A media/assets field fixture is required');
    if (!mediaField) return;
    await mediaField.locator('.blueprint-tree__field-main').click();
    const dialog = page.getByRole('dialog', { name: 'Modifier le champ' });
    await dialog.getByLabel('Politique alt').selectOption('decorative_allowed');
    await dialog.getByLabel('MIME autorisés').fill('image/png\nimage/webp');
    await dialog.getByText('Options expertes').click();
    await expect(dialog.getByLabel('Validation JSON')).toHaveValue(/decorative_allowed/);
    await expect(dialog.getByLabel('Validation JSON')).toHaveValue(/image\/png/);
    await dialog.getByRole('button', { name: 'Annuler', exact: true }).click();
  });
});
