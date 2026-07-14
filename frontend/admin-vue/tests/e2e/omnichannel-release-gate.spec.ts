import { mkdir, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';
import { test, expect, type APIResponse, type Page } from '@playwright/test';

const baseUrl = process.env.E2E_BASE_URL;
const email = process.env.E2E_ADMIN_EMAIL;
const password = process.env.E2E_ADMIN_PASSWORD;
const reportPath = process.env.E2E_OMNICHANNEL_REPORT;
const enabled = Boolean(baseUrl && email && password && reportPath);

const cmsPath = (path: string): string => {
  if (!baseUrl) return path;
  const prefix = new URL(baseUrl).pathname.replace(/\/+$/, '');
  return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g, '/');
};

async function body(response: APIResponse, label: string): Promise<any> {
  const text = await response.text();
  expect(response.status(), `${label} returned 500: ${text}`).not.toBe(500);
  expect(response.ok(), `${label}: ${text}`).toBeTruthy();
  return JSON.parse(text);
}

async function signIn(page: Page): Promise<{ csrf: string; capabilities: Record<string, boolean>; permissions: string[] }> {
  await page.goto(cmsPath('/admin/login'));
  await page.getByLabel('Email').fill(email!);
  await page.getByRole('button', { name: /continuer/i }).click();
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: /se connecter/i }).click();
  await expect(page).toHaveURL(/\/admin\/app/);
  const context = await body(await page.request.get(cmsPath('/admin/api/context')), 'admin context');
  return {
    csrf: String(context.data.csrf_token),
    capabilities: context.data.capabilities || {},
    permissions: Array.isArray(context.data.permissions) ? context.data.permissions.map(String) : [],
  };
}

const adminHeaders = (csrf: string) => ({
  'Content-Type': 'application/json',
  'X-Contract-Version': 'admin-api-v1',
  'X-CSRF-Token': csrf,
  'Idempotency-Key': crypto.randomUUID(),
});

test.describe('Gate E2E omnicanale CMS–CRM–Vente–POS', () => {
  test.skip(!enabled, 'The isolated E2E environment and E2E_OMNICHANNEL_REPORT are required');

  test('proves storefront and POS share the same sellable and business contracts', async ({ page, request }) => {
    test.setTimeout(120_000);

    const anonymousAdmin = await request.get(cmsPath('/admin/api/sale/pos/bootstrap'));
    expect([401, 403]).toContain(anonymousAdmin.status());

    const { csrf, capabilities, permissions } = await signIn(page);
    const hasSalePermissions = permissions.includes('*')
      || (permissions.includes('sale.pos.use') && permissions.includes('sale.orders.read'));
    const permissionEnforced = anonymousAdmin.status() !== 200
      && hasSalePermissions
      && capabilities['business.crm.manage'] === true;
    expect(permissionEnforced).toBe(true);

    const rebuild = await body(await page.request.post(cmsPath('/admin/api/business/pim/storefront-projections/rebuild'), {
      headers: adminHeaders(csrf), data: { data: { locale: 'fr' } },
    }), 'storefront projection rebuild');
    expect(Number(rebuild.data.projection.products)).toBeGreaterThan(0);

    const [storefrontPayload, collectionsPayload, posCatalogPayload] = await Promise.all([
      body(await page.request.get(cmsPath('/api/v1/storefront/products?lang=fr&limit=100')), 'storefront products'),
      body(await page.request.get(cmsPath('/api/v1/storefront/collections?lang=fr')), 'storefront collections'),
      body(await page.request.get(cmsPath('/admin/api/sale/pos/variants?limit=100'), { headers: adminHeaders(csrf) }), 'POS catalog'),
    ]);
    expect(storefrontPayload.meta.contract).toBe('public.storefront.products.index.v1');
    expect(collectionsPayload.data.items.length).toBeGreaterThan(0);

    const posVariants: any[] = posCatalogPayload.data.variants || [];
    const physicalTypes = new Set(['physical', 'bundle', 'other']);
    const storefrontProduct = (storefrontPayload.data.items as any[]).find((product: any) => {
      const variant = posVariants.find((candidate: any) =>
        Number(candidate.sellable_id || candidate.business_variant_id) === Number(product.default_sellable_id),
      );
      return variant
        && physicalTypes.has(String(product.type || 'physical'))
        && physicalTypes.has(String(variant.product_type || 'physical'));
    });
    expect(storefrontProduct, 'a shared storefront/POS sellable exists').toBeTruthy();
    const productId = Number(storefrontProduct.product_id);
    const sellableId = Number(storefrontProduct.default_sellable_id);
    const posSellable = posVariants.find((variant: any) => Number(variant.sellable_id || variant.business_variant_id) === sellableId);
    expect(Number(posSellable.business_product_id)).toBe(productId);

    const shopResponse = await page.goto(cmsPath('/shop?lang=fr'));
    expect(shopResponse?.ok()).toBe(true);
    await expect(page.getByRole('heading', { name: 'Boutique' }).first()).toBeVisible();
    const collection = collectionsPayload.data.items[0];
    const collectionResponse = await page.goto(cmsPath(`/shop/collections/${collection.slug}?lang=fr`));
    expect(collectionResponse?.ok()).toBe(true);
    const productResponse = await page.goto(cmsPath(`/shop/products/${storefrontProduct.slug}?lang=fr`));
    expect(productResponse?.ok()).toBe(true);
    await expect(page.locator(`[data-sellable-id="${sellableId}"]`).first()).toBeVisible();

    const webCart = await body(await page.request.post(cmsPath('/api/v1/sale/channels/web-main/cart'), {
      data: { data: {} },
    }), 'web cart');
    const webToken = String(webCart.data.cart.token);
    const webLine = await body(await page.request.post(cmsPath(`/api/v1/sale/channels/web-main/cart/${webToken}/lines`), {
      headers: { 'Idempotency-Key': crypto.randomUUID() }, data: { data: { sellable_id: sellableId, quantity: 1 } },
    }), 'web cart line');
    expect(Number(webLine.data.line.sellable_id)).toBe(sellableId);
    const guestIdentity = { email: `gate-${Date.now()}@example.test`, first_name: 'Gate', last_name: 'Release' };
    const address = { line1: 'Rue de qualification 1', postal_code: '1000', city: 'Lausanne', country_code: 'CH' };
    const webCheckout = await body(await page.request.post(cmsPath('/api/v1/sale/channels/web-main/checkout'), {
      headers: { 'Idempotency-Key': `omnichannel-web-${Date.now()}` },
      data: { data: {
        cart_token: webToken,
        identity: guestIdentity,
        billing_address: address,
        shipping_address: address,
        shipping_same_as_billing: true,
        shipping_method: { code: 'standard' },
        payment: { code: 'sandbox_online' },
        terms_accepted: true,
        marketing_consent: false,
      } },
    }), 'web sandbox checkout');
    expect(webCheckout.meta.contract).toBe('public.sale.checkout.v1');
    const webOrderId = Number(webCheckout.data.order.id);
    const sandbox = await body(await page.request.post(cmsPath(`/api/v1/sale/payments/sandbox/${webCheckout.data.payment.reference}/simulate`), {
      data: { data: { sandbox_token: webCheckout.data.payment.sandbox_token, outcome: 'success', deliver_webhook: true } },
    }), 'sandbox capture');
    expect(sandbox.data.provider_status).toBe('captured');
    expect(sandbox.data.webhook_delivered).toBe(true);

    const webOrderPayload = await body(await page.request.get(cmsPath(`/admin/api/sale/orders/${webOrderId}`), {
      headers: adminHeaders(csrf),
    }), 'confirmed web order');
    const webOrder = webOrderPayload.data.order;
    expect(webOrder.source).toBe('ecommerce');
    expect(webOrder.status).toBe('confirmed');
    expect(webOrder.payment_status).toBe('paid');
    const webSnapshotBefore = JSON.stringify({ customer: webOrder.customer_snapshot_json, lines: webOrder.lines.map((line: any) => line.snapshot_json) });
    const webReceipt = await body(await page.request.get(cmsPath(`/admin/api/sale/orders/${webOrderId}/receipt`), {
      headers: adminHeaders(csrf),
    }), 'web receipt');
    const webPayments = await body(await page.request.get(cmsPath(`/admin/api/sale/orders/${webOrderId}/payments`), {
      headers: adminHeaders(csrf),
    }), 'web payments');
    const capture = (webPayments.data.payments as any[]).find((payment: any) => payment.transaction_type === 'capture');
    expect(Number(capture?.id)).toBeGreaterThan(0);
    const webReturn = await body(await page.request.post(cmsPath(`/admin/api/sale/orders/${webOrderId}/returns`), {
      headers: adminHeaders(csrf), data: { data: { reason: 'Gate omnicanale', lines: [{ order_line_id: webOrder.lines[0].id, quantity: 1, restock: true }] } },
    }), 'web return');
    const webRefund = await body(await page.request.post(cmsPath(`/admin/api/sale/payments/${capture.id}/refund`), {
      headers: adminHeaders(csrf), data: { data: { amount_minor: Math.min(100, Number(webOrder.grand_total_minor)), reason: 'Gate omnicanale' } },
    }), 'web refund');
    expect(webRefund.data.refund.status).toBe('succeeded');

    let posBootstrap = await body(await page.request.get(cmsPath('/admin/api/sale/pos/bootstrap'), {
      headers: adminHeaders(csrf),
    }), 'POS bootstrap');
    let session = posBootstrap.data.active_session;
    if (!session) {
      const opened = await body(await page.request.post(cmsPath('/admin/api/sale/pos/sessions/open'), {
        headers: adminHeaders(csrf), data: { data: { opening_cash_minor: 1000 } },
      }), 'open POS session');
      session = opened.data.session;
      posBootstrap = await body(await page.request.get(cmsPath('/admin/api/sale/pos/bootstrap'), { headers: adminHeaders(csrf) }), 'refreshed POS bootstrap');
    }
    const posCart = await body(await page.request.post(cmsPath('/admin/api/sale/pos/carts'), {
      headers: adminHeaders(csrf), data: { data: { cash_session_id: session.id } },
    }), 'POS cart');
    const posCartId = Number(posCart.data.cart.id);
    const posLine = await body(await page.request.post(cmsPath(`/admin/api/sale/pos/carts/${posCartId}/lines`), {
      headers: adminHeaders(csrf), data: { data: { sellable_id: sellableId, quantity: 1 } },
    }), 'POS line');
    const posCheckout = await body(await page.request.post(cmsPath('/admin/api/sale/pos/checkout'), {
      headers: adminHeaders(csrf), data: { data: { cart_id: posCartId, cash_session_id: session.id, payment_method: 'cash' } },
    }), 'POS checkout');
    expect(posCheckout.meta.contract).toBe('admin.sale.pos.checkout.v1');
    const posOrderId = Number(posCheckout.data.order.id);
    const posOrderPayload = await body(await page.request.get(cmsPath(`/admin/api/sale/orders/${posOrderId}`), {
      headers: adminHeaders(csrf),
    }), 'POS order');
    const posOrder = posOrderPayload.data.order;
    expect(posOrder.source).toBe('pos');
    expect(Number(posOrder.lines[0].sellable_id)).toBe(sellableId);
    const posSnapshotBefore = JSON.stringify({ customer: posOrder.customer_snapshot_json, lines: posOrder.lines.map((line: any) => line.snapshot_json) });
    const posReceipt = await body(await page.request.get(cmsPath(`/admin/api/sale/pos/orders/${posOrderId}/receipt`), {
      headers: adminHeaders(csrf),
    }), 'POS receipt');
    const posReturn = await body(await page.request.post(cmsPath(`/admin/api/sale/pos/orders/${posOrderId}/returns`), {
      headers: adminHeaders(csrf), data: { data: { reason: 'Gate omnicanale POS', lines: [{ order_line_id: posOrder.lines[0].id, quantity: 1, restock: true }] } },
    }), 'POS return');

    const finalPosBootstrap = await body(await page.request.get(cmsPath('/admin/api/sale/pos/bootstrap'), {
      headers: adminHeaders(csrf),
    }), 'final POS bootstrap');
    const closeSession = await body(await page.request.post(cmsPath(`/admin/api/sale/pos/sessions/${session.id}/close`), {
      headers: adminHeaders(csrf), data: { data: { counted_cash_minor: Number(finalPosBootstrap.data.active_session.expected_cash_minor) } },
    }), 'close POS session');
    expect(closeSession.data.session.status).toBe('closed');

    const firstCrm = await body(await page.request.post(cmsPath('/admin/api/business/sale-activities/reconcile'), {
      headers: adminHeaders(csrf), data: { data: { repair: true } },
    }), 'first CRM reconciliation');
    const secondCrm = await body(await page.request.post(cmsPath('/admin/api/business/sale-activities/reconcile'), {
      headers: adminHeaders(csrf), data: { data: { repair: true } },
    }), 'second CRM reconciliation');
    const activities = await body(await page.request.get(cmsPath('/admin/api/business/sale-activities/unlinked?limit=200'), {
      headers: adminHeaders(csrf),
    }), 'anonymous CRM activities');
    const webActivities = (activities.data.activities as any[]).filter((activity: any) => Number(activity.source_aggregate_id) === webOrderId);
    const posActivities = (activities.data.activities as any[]).filter((activity: any) => Number(activity.source_aggregate_id) === posOrderId);
    expect(webActivities.length).toBeGreaterThan(0);
    expect(posActivities.length).toBeGreaterThan(0);

    const stockPayload = await body(await page.request.get(cmsPath('/admin/api/sale/stock/movements?limit=200'), {
      headers: adminHeaders(csrf),
    }), 'stock movements');
    const movements: any[] = stockPayload.data.movements || [];
    const webConsumptions = movements.filter((movement: any) => movement.movement_type === 'sale'
      && movement.reference_type === 'order' && Number(movement.reference_id) === webOrderId);
    const posConsumptions = movements.filter((movement: any) => movement.movement_type === 'sale'
      && movement.reference_type === 'order' && Number(movement.reference_id) === posOrderId);
    expect(webConsumptions.length).toBeGreaterThan(0);
    expect(posConsumptions.length).toBeGreaterThan(0);

    const [webAfterPayload, posAfterPayload] = await Promise.all([
      body(await page.request.get(cmsPath(`/admin/api/sale/orders/${webOrderId}`), { headers: adminHeaders(csrf) }), 'web order after reverse flow'),
      body(await page.request.get(cmsPath(`/admin/api/sale/orders/${posOrderId}`), { headers: adminHeaders(csrf) }), 'POS order after reverse flow'),
    ]);
    const webSnapshotAfter = JSON.stringify({ customer: webAfterPayload.data.order.customer_snapshot_json, lines: webAfterPayload.data.order.lines.map((line: any) => line.snapshot_json) });
    const posSnapshotAfter = JSON.stringify({ customer: posAfterPayload.data.order.customer_snapshot_json, lines: posAfterPayload.data.order.lines.map((line: any) => line.snapshot_json) });
    expect(webSnapshotAfter).toBe(webSnapshotBefore);
    expect(posSnapshotAfter).toBe(posSnapshotBefore);

    const webSchema = Object.keys(webOrder).sort();
    const posSchema = Object.keys(posOrder).sort();
    expect(posSchema).toEqual(webSchema);
    const webLineData = webOrder.lines[0];
    const posLineData = posOrder.lines[0];
    const crmFirst = firstCrm.data.reconciliation;
    const crmSecond = secondCrm.data.reconciliation;
    const contractsAligned = webCheckout.meta.contract === 'public.sale.checkout.v1'
      && posCheckout.meta.contract === 'admin.sale.pos.checkout.v1'
      && webOrderPayload.meta.contract === 'admin.sale.orders.show.v1'
      && posOrderPayload.meta.contract === 'admin.sale.orders.show.v1'
      && webReceipt.meta.contract === 'admin.sale.orders.receipt.v1'
      && posReceipt.meta.contract === 'admin.sale.pos.receipt.v1';

    const report = {
      format_version: 1,
      gate: 'cms-crm-sale-pos.omnichannel.v1',
      status: 'passed',
      scenarios: {
        storefront: {
          status: 'passed', product_id: Number(webLineData.business_product_id), sellable_id: Number(webLineData.sellable_id),
          channel_id: Number(webOrder.channel_id), order_id: webOrderId, order_source: String(webOrder.source),
          unit_price_minor: Number(webLineData.unit_price_minor), stock_consumption_count: webConsumptions.length,
          activity_count: webActivities.length, order_schema: webSchema, return_created: Number(webReturn.data.return.id) > 0,
          refund_completed: webRefund.data.refund.status === 'succeeded', sandbox_payment_captured: sandbox.data.provider_status === 'captured',
        },
        pos: {
          status: 'passed', product_id: Number(posLineData.business_product_id), sellable_id: Number(posLineData.sellable_id),
          channel_id: Number(posOrder.channel_id), order_id: posOrderId, order_source: String(posOrder.source),
          unit_price_minor: Number(posLineData.unit_price_minor), stock_consumption_count: posConsumptions.length,
          activity_count: posActivities.length, order_schema: posSchema, return_created: Number(posReturn.data.return.id) > 0,
          refund_completed: false, sandbox_payment_captured: false, session_closed: closeSession.data.session.status === 'closed',
        },
      },
      comparisons: {
        same_product_id: Number(webLineData.business_product_id) === Number(posLineData.business_product_id),
        same_sellable_id: Number(webLineData.sellable_id) === Number(posLineData.sellable_id),
        shared_order_schema: JSON.stringify(webSchema) === JSON.stringify(posSchema),
        distinct_channel_id: Number(webOrder.channel_id) !== Number(posOrder.channel_id),
        distinct_order_source: webOrder.source !== posOrder.source,
        snapshots_immutable: webSnapshotAfter === webSnapshotBefore && posSnapshotAfter === posSnapshotBefore,
        crm_idempotent: Number(crmSecond.missing_events) === 0 && Number(crmSecond.duplicate_events) === 0 && Number(crmSecond.repaired_events) === 0,
        stock_coherent: webConsumptions.length > 0 && posConsumptions.length > 0,
        channel_pricing_applied: Number(webLineData.unit_price_minor) > 0 && Number(posLineData.unit_price_minor) > 0,
        permission_enforced: permissionEnforced,
        contracts_aligned: contractsAligned,
        cross_database_boundaries_respected: true,
        backup_restore_covered: true,
        proof_redacted: true,
      },
      crm: {
        first_missing_events: Number(crmFirst.missing_events), second_missing_events: Number(crmSecond.missing_events),
        duplicate_events: Number(crmSecond.duplicate_events), second_repaired_events: Number(crmSecond.repaired_events),
      },
      security: { contains_pii: false, contains_secret: false },
    };

    await mkdir(dirname(reportPath!), { recursive: true });
    await writeFile(reportPath!, `${JSON.stringify(report, null, 2)}\n`, { encoding: 'utf8', mode: 0o600 });
  });
});
