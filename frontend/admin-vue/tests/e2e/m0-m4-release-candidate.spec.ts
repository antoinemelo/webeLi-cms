import { test, expect, type APIResponse, type Page } from '@playwright/test';

const baseUrl=process.env.E2E_BASE_URL; const email=process.env.E2E_ADMIN_EMAIL; const password=process.env.E2E_ADMIN_PASSWORD;
const enabled=Boolean(baseUrl&&email&&password);
const cmsPath=(path:string):string=>{ if(!baseUrl)return path; const prefix=new URL(baseUrl).pathname.replace(/\/+$/,''); return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g,'/'); };
async function body(response:APIResponse,label:string):Promise<any>{ const text=await response.text(); expect(response.status(),`${label} returned 500: ${text}`).not.toBe(500); expect(response.ok(),`${label}: ${text}`).toBeTruthy(); return JSON.parse(text); }
async function signIn(page:Page):Promise<string>{ await page.goto(cmsPath('/admin/login')); await page.getByLabel('Email').fill(email!); await page.getByRole('button',{name:/continuer/i}).click(); await page.locator('input[name="password"]').fill(password!); await page.getByRole('button',{name:/se connecter/i}).click(); await expect(page).toHaveURL(/\/admin\/app/); const context=await body(await page.request.get(cmsPath('/admin/api/context')),'admin context'); return String(context.data.csrf_token); }
const adminHeaders=(csrf:string)=>({'Content-Type':'application/json','X-Contract-Version':'admin-api-v1','X-CSRF-Token':csrf,'Idempotency-Key':crypto.randomUUID()});

test.describe('M0-M4 release candidate commerce flow',()=>{
  test.skip(!enabled,'Dedicated E2E environment and administrator credentials are required');
  test('publishes, checks out, pays, receipts, returns, refunds and links account',async({page})=>{
    const catalog=await body(await page.request.get(cmsPath('/api/v1/catalog/products?channel=ecommerce&limit=1')),'published catalog');
    const product=catalog.data.items[0]; const variantId=Number(product.variants[0].id); expect(variantId).toBeGreaterThan(0);
    const cart=await body(await page.request.post(cmsPath('/api/v1/sale/channels/web-main/cart'),{data:{data:{}}}),'cart'); const token=String(cart.data.cart.token);
    await body(await page.request.post(cmsPath(`/api/v1/sale/channels/web-main/cart/${token}/lines`),{headers:{'Idempotency-Key':crypto.randomUUID()},data:{data:{sellable_id:variantId,quantity:1}}}),'cart line');
    await page.goto(cmsPath(`/checkout?channel=web-main&cart_token=${token}&lang=fr`)); await expect(page.getByText(product.name,{exact:false}).first()).toBeVisible();
    const identity={email:`m0-m4-${Date.now()}@example.test`,first_name:'Release',last_name:'Candidate'}; const address={line1:'Rue du Test 1',postal_code:'1000',city:'Lausanne',country_code:'CH'};
    const checkoutData={cart_token:token,identity,billing_address:address,shipping_address:address,shipping_same_as_billing:true,shipping_method:{code:'standard'},payment:{code:'manual'},terms_accepted:true,marketing_consent:false};
    const checkout=await body(await page.request.post(cmsPath('/api/v1/sale/channels/web-main/checkout'),{headers:{'Idempotency-Key':`m0-m4-${Date.now()}`},data:{data:checkoutData}}),'checkout');
    const order=checkout.data.order; expect(order.tax_total_minor).toBeGreaterThan(0); expect(order.shipping_total_minor).toBeGreaterThanOrEqual(0); expect(order.checkout.shipping_method.code).toBe('standard');
    const csrf=await signIn(page);
    const adminOrder=await body(await page.request.get(cmsPath(`/admin/api/sale/orders/${order.id}`),{headers:adminHeaders(csrf)}),'admin order'); expect(adminOrder.data.order.order_number).toBe(order.order_number);
    const paid=await body(await page.request.post(cmsPath(`/admin/api/sale/orders/${order.id}/payments`),{headers:adminHeaders(csrf),data:{data:{amount_minor:order.grand_total_minor,provider_key:'cash'}}}),'local payment');
    const transactionId=Number(paid.data.transaction.id); expect(transactionId).toBeGreaterThan(0);
    const receipt=await body(await page.request.get(cmsPath(`/admin/api/sale/orders/${order.id}/receipt`),{headers:adminHeaders(csrf)}),'receipt'); expect(receipt.data.receipt).toBeTruthy();
    const lineId=Number(adminOrder.data.order.lines[0].id);
    await body(await page.request.post(cmsPath(`/admin/api/sale/orders/${order.id}/returns`),{headers:adminHeaders(csrf),data:{data:{reason:'Qualification M0-M4',lines:[{order_line_id:lineId,quantity:1,restock:true}]}}}),'partial return');
    await body(await page.request.post(cmsPath(`/admin/api/sale/payments/${transactionId}/refund`),{headers:adminHeaders(csrf),data:{data:{amount_minor:Math.min(100,order.grand_total_minor),reason:'Qualification M0-M4'}}}),'partial refund');
    const [timeline,events,stock]=await Promise.all([
      body(await page.request.get(cmsPath(`/admin/api/sale/orders/${order.id}/timeline`),{headers:adminHeaders(csrf)}),'timeline'),
      body(await page.request.get(cmsPath(`/admin/api/sale/orders/${order.id}/events`),{headers:adminHeaders(csrf)}),'events'),
      body(await page.request.get(cmsPath('/admin/api/sale/stock/items'),{headers:adminHeaders(csrf)}),'stock'),
    ]); expect(timeline.data.timeline.length).toBeGreaterThan(0); expect(events.data.events.length).toBeGreaterThan(0); expect(stock.data.items).toBeDefined();
    const account=await body(await page.request.post(cmsPath('/api/v1/customer/accounts/register'),{data:{data:{proof_token:checkout.data.account_creation.token,password:'release-candidate-password'}}}),'optional account'); expect(account.data.user.id).toBeGreaterThan(0);
  });
});
