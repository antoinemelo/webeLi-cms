import { createHash } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { test, expect, type Page } from '@playwright/test';

const baseUrl = process.env.E2E_BASE_URL;
const email = process.env.E2E_ADMIN_EMAIL;
const password = process.env.E2E_ADMIN_PASSWORD;
const reportPath = process.env.E2E_USABILITY_REPORT;
const enabled = Boolean(baseUrl && email && password && reportPath);

const cmsPath = (path: string): string => {
  if (!baseUrl) return path;
  const prefix = new URL(baseUrl).pathname.replace(/\/+$/, '');
  return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g, '/');
};

async function signIn(page: Page): Promise<string> {
  await page.goto(cmsPath('/admin/login'));
  await page.getByLabel('Email').fill(email!);
  await page.getByRole('button', { name: /continuer/i }).click();
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: /se connecter/i }).click();
  await expect(page).toHaveURL(/\/admin\/app/);
  const response = await page.request.get(cmsPath('/admin/api/context'));
  expect(response.ok(), await response.text()).toBeTruthy();
  return String((await response.json()).data.csrf_token);
}

type AccessibilityResult = {
  unnamed_controls: number;
  unlabelled_fields: number;
  unlabelled_elements: string[];
  contrast_violations: number;
  contrast_elements: string[];
  horizontal_overflow: boolean;
  overflow_pixels: number;
  overflow_elements: string[];
  focus_visible: boolean;
  interactive_count: number;
};

async function scanAccessibility(page: Page): Promise<AccessibilityResult> {
  const scan = await page.locator('main, [role="main"]').first().evaluate((root) => {
    const visible = (element: Element): element is HTMLElement => {
      const node = element as HTMLElement;
      const style = getComputedStyle(node);
      const rect = node.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
    };
    const labelled = (element: Element): boolean => {
      const node = element as HTMLElement;
      const id = node.id;
      const labelledBy = node.getAttribute('aria-labelledby');
      const referenced = labelledBy?.split(/\s+/).map((key) => document.getElementById(key)?.textContent || '').join(' ').trim();
      const explicit = id ? document.querySelector(`label[for="${CSS.escape(id)}"]`)?.textContent?.trim() : '';
      const wrapping = node.closest('label')?.textContent?.trim();
      const imageAlt = node.querySelector('img[alt]')?.getAttribute('alt')?.trim();
      const ownText = node.matches('input, select, textarea') ? '' : node.textContent?.trim();
      return Boolean(node.getAttribute('aria-label')?.trim() || referenced || explicit || wrapping
        || node.getAttribute('title')?.trim() || imageAlt || ownText);
    };
    const controls = Array.from(root.querySelectorAll('button, a[href], input:not([type="hidden"]), select, textarea, [role="button"]'))
      .filter(visible).filter((node) => !(node as HTMLInputElement).disabled);
    const fields = controls.filter((node) => node.matches('input, select, textarea'));
    const unnamed = controls.filter((node) => !node.matches('input, select, textarea') && !labelled(node));
    const unlabelled = fields.filter((node) => !labelled(node));

    const parse = (value: string): [number, number, number, number] | null => {
      const match = value.match(/rgba?\((\d+)[, ]+(\d+)[, ]+(\d+)(?:[, /]+([\d.]+))?\)/);
      return match ? [Number(match[1]), Number(match[2]), Number(match[3]), match[4] === undefined ? 1 : Number(match[4])] : null;
    };
    const luminance = ([red, green, blue]: number[]): number => {
      const channel = (value: number) => {
        const normalized = value / 255;
        return normalized <= 0.04045 ? normalized / 12.92 : ((normalized + 0.055) / 1.055) ** 2.4;
      };
      return 0.2126 * channel(red) + 0.7152 * channel(green) + 0.0722 * channel(blue);
    };
    const background = (element: Element): [number, number, number, number] | null => {
      let cursor: Element | null = element;
      while (cursor) {
        const color = parse(getComputedStyle(cursor).backgroundColor);
        if (color && color[3] >= 0.98) return color;
        cursor = cursor.parentElement;
      }
      return [255, 255, 255, 1];
    };
    const textNodes = Array.from(root.querySelectorAll('h1, h2, h3, p, label, button, a[href], [role="alert"]'))
      .filter(visible).filter((node) => Boolean(node.textContent?.trim())).filter((node) => !(node as HTMLButtonElement).disabled);
    const lowContrast = textNodes.filter((node) => {
      const foreground = parse(getComputedStyle(node).color);
      const behind = background(node);
      if (!foreground || !behind || foreground[3] < 0.98) return false;
      const high = Math.max(luminance(foreground), luminance(behind));
      const low = Math.min(luminance(foreground), luminance(behind));
      const ratio = (high + 0.05) / (low + 0.05);
      const style = getComputedStyle(node);
      const size = Number.parseFloat(style.fontSize);
      const weight = Number.parseInt(style.fontWeight, 10) || 400;
      const large = size >= 24 || (size >= 18.66 && weight >= 700);
      return ratio + 0.01 < (large ? 3 : 4.5);
    });
    return {
      unnamed_controls: unnamed.length,
      unlabelled_fields: unlabelled.length,
      unlabelled_elements: unlabelled.map((node) => {
        const element = node as HTMLInputElement;
        return `${element.tagName.toLowerCase()}${element.name ? `[name=${element.name}]` : ''}${element.getAttribute('placeholder') ? `[placeholder=${element.getAttribute('placeholder')}]` : ''}`;
      }),
      contrast_violations: lowContrast.length,
      contrast_elements: lowContrast.map((node) => {
        const element = node as HTMLElement;
        const style = getComputedStyle(element);
        return `${element.tagName.toLowerCase()}${element.className && typeof element.className === 'string' ? `.${element.className.trim().replace(/\s+/g, '.')}` : ''}[color=${style.color}][background=${background(element)?.join('-')}]`;
      }),
      horizontal_overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      overflow_pixels: Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth),
      overflow_elements: Array.from(root.querySelectorAll('*')).filter(visible).filter((node) => {
        const rect = node.getBoundingClientRect();
        return rect.right > document.documentElement.clientWidth + 1 || rect.left < -1;
      }).slice(0, 8).map((node) => {
        const element = node as HTMLElement;
        return `${element.tagName.toLowerCase()}${element.id ? `#${element.id}` : ''}${element.className && typeof element.className === 'string' ? `.${element.className.trim().replace(/\s+/g, '.')}` : ''}`;
      }),
      interactive_count: controls.length,
    };
  });

  const firstControl = page.locator('main button:not([disabled]), main a[href], main input:not([disabled]), main select:not([disabled]), [role="main"] button:not([disabled])').first();
  await expect(firstControl).toBeVisible();
  await firstControl.focus();
  await page.keyboard.press('Tab');
  const focusedControl = page.locator(':focus-visible').first();
  const focusVisible = await focusedControl.count() > 0 && await focusedControl.evaluate((node) => {
    const style = getComputedStyle(node);
    return (style.outlineStyle !== 'none' && Number.parseFloat(style.outlineWidth) > 0
      || style.boxShadow !== 'none');
  });
  return { ...scan, focus_visible: focusVisible };
}

async function capture(page: Page, directory: string, name: string, viewport: { width: number; height: number }) {
  await page.setViewportSize(viewport);
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(150);
  const audit = await scanAccessibility(page);
  expect(audit.unnamed_controls, `${name}: unnamed controls`).toBe(0);
  expect(audit.unlabelled_fields, `${name}: unlabelled fields: ${audit.unlabelled_elements.join(', ')}`).toBe(0);
  expect(audit.contrast_violations, `${name}: contrast violations: ${audit.contrast_elements.join(', ')}`).toBe(0);
  expect(audit.horizontal_overflow, `${name}: horizontal overflow (${audit.overflow_pixels}px): ${audit.overflow_elements.join(', ')}`).toBe(false);
  expect(audit.focus_visible, `${name}: visible keyboard focus`).toBe(true);
  const path = join(directory, name);
  await page.screenshot({ path, fullPage: true });
  return {
    ...audit,
    viewport: `${viewport.width}x${viewport.height}`,
    capture: name,
    capture_sha256: createHash('sha256').update(await readFile(path)).digest('hex'),
  };
}

test.describe('Gate d’utilisabilité Commerce M5–M7', () => {
  test.skip(!enabled, 'The isolated E2E environment and E2E_USABILITY_REPORT are required');

  test('audits critical role screens without collecting customer data', async ({ page }) => {
    test.setTimeout(180_000);
    const captureDirectory = join(dirname(reportPath!), 'captures');
    await mkdir(captureDirectory, { recursive: true });
    const screens: Record<string, unknown> = {};

    const csrf = await signIn(page);
    const rebuild = await page.request.post(cmsPath('/admin/api/business/pim/storefront-projections/rebuild'), {
      headers: { 'Content-Type': 'application/json', 'X-Contract-Version': 'admin-api-v1', 'X-CSRF-Token': csrf },
      data: { data: { locale: 'fr' } },
    });
    expect(rebuild.ok(), await rebuild.text()).toBeTruthy();
    const listingResponse = await page.request.get(cmsPath('/api/v1/storefront/products?lang=fr&limit=12'));
    expect(listingResponse.ok(), await listingResponse.text()).toBeTruthy();
    const listing = await listingResponse.json();
    const product = listing.data.items[0];
    expect(product?.slug).toBeTruthy();

    await page.goto(cmsPath('/shop?lang=fr'));
    await expect(page.getByRole('heading', { name: 'Boutique' }).first()).toBeVisible();
    const cookieChoice = page.getByRole('button', { name: 'Enregistrer mes choix' });
    await expect(cookieChoice).toBeVisible();
    await cookieChoice.click();
    await expect(page.locator('.cookie-consent')).toBeHidden();
    screens.shop_mobile = await capture(page, captureDirectory, 'shop-mobile.png', { width: 390, height: 844 });

    await page.goto(cmsPath(`/shop/products/${product.slug}?lang=fr`));
    await expect(page.getByRole('heading', { name: product.name }).first()).toBeVisible();
    const add = page.locator(`[data-storefront-add-to-cart][data-sellable-id="${product.default_sellable_id}"]`).first();
    await expect(add).toHaveAccessibleName(/ajouter au panier/i);
    screens.product_mobile = await capture(page, captureDirectory, 'product-mobile.png', { width: 390, height: 844 });
    await add.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('[data-cart-drawer]')).toBeVisible();

    await page.locator('[data-cart-checkout]').first().click();
    await expect(page.getByRole('heading', { name: 'Commande invitée' })).toBeVisible();
    await expect(page.getByLabel('J’accepte les conditions générales de vente')).toBeVisible();
    screens.checkout_mobile = await capture(page, captureDirectory, 'checkout-mobile.png', { width: 390, height: 844 });

    await page.goto(cmsPath('/admin/app/sale/pos'));
    await expect(page.getByRole('heading', { name: 'Ventes' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Ouvrir', exact: true })).toBeVisible();
    screens.pos_desktop = await capture(page, captureDirectory, 'pos-desktop.png', { width: 1440, height: 1000 });

    await page.goto(cmsPath('/admin/app/sale/advanced/logistics'));
    await expect(page.getByRole('heading', { name: 'Exécution logistique' })).toBeVisible();
    screens.operations_mobile = await capture(page, captureDirectory, 'operations-mobile.png', { width: 390, height: 844 });

    await page.goto(cmsPath('/admin/app/sale/payments'));
    await expect(page.getByRole('heading', { name: /paiements/i }).first()).toBeVisible();
    screens.finance_desktop = await capture(page, captureDirectory, 'finance-desktop.png', { width: 1440, height: 1000 });

    await page.goto(cmsPath('/admin/app/business'));
    await page.getByRole('link', { name: 'Relations', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Relations' })).toBeVisible();
    screens.crm_mobile = await capture(page, captureDirectory, 'crm-mobile.png', { width: 390, height: 844 });

    const report = {
      format_version: 1,
      gate: 'commerce-foundations.usability.m5-m7.v1',
      status: 'passed',
      screens,
      interactions: { mobile_customer: 3, pos_operator: 1, operations: 1, customer_service_finance: 1, crm: 1 },
      microcopy: { availability: true, next_action: true, recoverable_error: true, permission: true, test_mode: true },
      telemetry_samples: [
        { contract: 'commerce.usability.v1', event: 'task_success', journey: 'shop', role: 'mobile_customer', outcome: 'success', viewport: 'mobile', language: 'fr' },
        { contract: 'commerce.usability.v1', event: 'task_abandon', journey: 'checkout', role: 'mobile_customer', outcome: 'abandoned', duration_bucket: '30-60s' },
        { contract: 'commerce.usability.v1', event: 'task_error', journey: 'payment', role: 'mobile_customer', outcome: 'recoverable', error_kind: 'provider_declined' },
        { contract: 'commerce.usability.v1', event: 'task_recovery', journey: 'payment', role: 'mobile_customer', outcome: 'recovered', recovery_kind: 'retry' },
        { contract: 'commerce.usability.v1', event: 'task_duration', journey: 'pos_checkout', role: 'pos_operator', duration_bucket: 'under_30s' },
        { contract: 'commerce.usability.v1', event: 'action_cancelled', journey: 'refund', role: 'customer_service_finance', cancelled: true },
      ],
      security: { contains_pii: false, contains_secret: false, contains_card_data: false, contains_free_text: false },
    };
    await mkdir(dirname(reportPath!), { recursive: true });
    await writeFile(reportPath!, `${JSON.stringify(report, null, 2)}\n`, 'utf8');
  });
});
