import { test, expect, type Page } from '@playwright/test';

const baseUrl=process.env.E2E_BASE_URL; const email=process.env.E2E_ADMIN_EMAIL; const password=process.env.E2E_ADMIN_PASSWORD;
const enabled=Boolean(baseUrl&&email&&password);
const cmsPath=(path:string):string=>{ if(!baseUrl)return path; const prefix=new URL(baseUrl).pathname.replace(/\/+$/,''); return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g,'/'); };
async function signIn(page:Page):Promise<string>{ await page.goto(cmsPath('/admin/login')); await page.getByLabel('Email').fill(email!); await page.getByRole('button',{name:/continuer/i}).click(); await page.locator('input[name="password"]').fill(password!); await page.getByRole('button',{name:/se connecter/i}).click(); await expect(page).toHaveURL(/\/admin\/app/); const response=await page.request.get(cmsPath('/admin/api/context')); return String((await response.json()).data.csrf_token); }

test.describe('Storefront projections SSR and headless',()=>{
  test.skip(!enabled,'Dedicated E2E environment and administrator credentials are required');
  test('rebuilds once and serves the same sellable through headless and SSR',async({page})=>{
    const csrf=await signIn(page);
    const rebuild=await page.request.post(cmsPath('/admin/api/business/pim/storefront-projections/rebuild'),{headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,'X-Contract-Version':'admin-api-v1'},data:{data:{locale:'fr'}}});
    expect(rebuild.ok(),await rebuild.text()).toBeTruthy();
    const listing=await page.request.get(cmsPath('/api/v1/storefront/products?lang=fr&limit=12'));
    expect(listing.ok(),await listing.text()).toBeTruthy(); const payload=await listing.json();
    expect(payload.data.items.length).toBeGreaterThan(0); const product=payload.data.items[0];
    expect(product.contract).toBe('storefront.product.v1'); expect(product.default_sellable_id).toBeGreaterThan(0);
    await page.goto(cmsPath(`/shop/products/${product.slug}?lang=fr`));
    await expect(page.getByRole('heading',{name:product.name}).first()).toBeVisible();
    await expect(page.locator(`[data-sellable-id="${product.default_sellable_id}"]`).first()).toBeVisible();
    await page.locator(`[data-storefront-add-to-cart][data-sellable-id="${product.default_sellable_id}"]`).first().click();
    await expect(page.locator('[data-cart-count]')).toHaveText('1');
    await expect(page.locator('[data-cart-drawer]')).toBeVisible();
    await page.goto(cmsPath('/cart'));
    await expect(page.locator('[data-cart-page]')).toBeVisible();
    await expect(page.locator('[data-cart-lines] .cart-line')).toHaveCount(1);
    await expect(page.locator('[data-cart-total]')).toContainText('Total');
  });
});
