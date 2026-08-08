import { test, expect, type Page } from '@playwright/test';

const baseUrl=process.env.E2E_BASE_URL; const email=process.env.E2E_ADMIN_EMAIL; const password=process.env.E2E_ADMIN_PASSWORD;
const enabled=Boolean(baseUrl&&email&&password);
const cmsPath=(path:string):string=>{ if(!baseUrl)return path; const prefix=new URL(baseUrl).pathname.replace(/\/+$/,''); return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g,'/'); };
async function signIn(page:Page):Promise<string>{ await page.goto(cmsPath('/admin/login')); await page.getByLabel('Email').fill(email!); await page.getByRole('button',{name:/continuer/i}).click(); await page.locator('input[name="password"]').fill(password!); await page.getByRole('button',{name:/se connecter/i}).click(); await expect(page).toHaveURL(/\/admin\/app/); const response=await page.request.get(cmsPath('/admin/api/context')); return String((await response.json()).data.csrf_token); }

test.describe('42 cartes, fiches, variantes et produits liés',()=>{
  test.skip(!enabled,'Dedicated E2E environment and administrator credentials are required');

  test('shares one accessible product experience across every supported theme',async({page})=>{
    test.setTimeout(240_000);
    const csrf=await signIn(page);
    const rebuild=await page.request.post(cmsPath('/admin/api/business/pim/storefront-projections/rebuild'),{headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,'X-Contract-Version':'admin-api-v1'},data:{data:{locale:'fr'}}});
    expect(rebuild.ok(),await rebuild.text()).toBeTruthy();

    const response=await page.request.get(cmsPath('/api/v1/storefront/products?lang=fr&limit=100'));
    expect(response.ok(),await response.text()).toBeTruthy();
    const products=(await response.json()).data.items as Array<Record<string,any>>;
    expect(products.length).toBeGreaterThan(0);
    expect([...new Set(products.map(product=>product.type))]).toEqual(expect.arrayContaining(['physical','service','gift_card','bundle']));
    expect(products.every(product=>product.contract==='storefront.product.v3'&&String(product.card_summary||'').length<=70)).toBeTruthy();
    expect(products.every(product=>['available','last_available','on_order','unavailable'].includes(product.availability.display_status))).toBeTruthy();
    expect(products.filter(product=>!product.availability.is_orderable).every(product=>product.cta===null)).toBeTruthy();
    const product=products.find(item=>Array.isArray(item.sellables)&&item.sellables.length>1)||products[0];

    for(const theme of ['default','aurora','pulse']){
      await page.goto(cmsPath(`/shop?lang=fr&_theme=${theme}`));
      const card=page.locator('[data-shop-section="catalog"] [data-product-id]').first();
      await expect(card.getByRole('link',{name:/voir/i}).first()).toBeVisible();
      await expect(card.locator('.storefront-availability')).toBeVisible();
      const preview=card.locator('[data-product-preview]');
      // The preview intentionally covers its trigger as soon as mouseenter fires.
      // Dispatching that event avoids Playwright retrying a hover which already succeeded.
      await card.locator('[data-product-preview-trigger]').first().dispatchEvent('mouseenter');
      await expect(preview).toHaveAttribute('open','');
      const cardAdd=card.locator('[data-storefront-add-to-cart]');
      if(await cardAdd.count()&&await cardAdd.isDisabled()){
        const choice=preview.locator('[data-product-variant-choice]:not([disabled])').first();
        await choice.click();
        await expect(choice).toHaveAttribute('aria-pressed','true');
        await expect(cardAdd).toBeEnabled();
      }
      await page.keyboard.press('Escape');
      await expect(preview).not.toHaveAttribute('open','');
      if(theme==='default'){
        if(await cardAdd.count()){
          const cartCount=page.locator('[data-cart-count]');
          const previousCount=Number(await cartCount.textContent()||0);
          await cardAdd.click();
          await expect.poll(async()=>Number(await cartCount.textContent()||0),{timeout:60_000}).toBeGreaterThan(previousCount);
          await expect(page.locator('[data-cart-drawer]')).toBeVisible({timeout:60_000});
          await expect(page.locator('[data-cart-lines] .cart-line').first()).toBeVisible();
          await page.locator('[data-cart-close]').click();
        }
      }

      await page.goto(cmsPath(`${product.url}?lang=fr&_theme=${theme}`));
      await expect(page.getByRole('heading',{level:1,name:product.name})).toBeVisible();
      await expect(page.getByLabel('Quantité')).toHaveValue('1');
      await expect(page.getByRole('heading',{name:'Livraison et paiement'})).toBeVisible();
      const select=page.locator('[data-product-variant]');
      if(await select.count()&&product.sellables.length>1){
        const target=product.sellables[1];
        await select.selectOption(String(target.sellable_id));
        await expect(page).toHaveURL(new RegExp(`variant=${target.sellable_id}`));
        await expect(page.locator('[data-product-sku]')).toHaveText(target.sku);
        await expect(page.locator('[data-product-announcement]')).not.toHaveText('');
        if(target.orderable) await expect(page.locator('[data-product-add]')).toBeVisible(); else await expect(page.locator('[data-product-add]')).toBeHidden();
      }
      const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>document.documentElement.clientWidth);
      expect(overflow,`${theme} desktop has no horizontal overflow`).toBe(false);
    }

    await page.setViewportSize({width:390,height:820});
    await page.goto(cmsPath(`${product.url}?lang=fr&_theme=default`));
    await expect(page.getByRole('heading',{level:1,name:product.name})).toBeVisible();
    expect(await page.evaluate(()=>document.documentElement.scrollWidth>document.documentElement.clientWidth),'mobile has no horizontal overflow').toBe(false);
    const detailAdd=page.locator('[data-product-add]');
    if(await detailAdd.isVisible()){
      const cartCount=page.locator('[data-cart-count]');
      const previousCount=Number(await cartCount.textContent()||0);
      await detailAdd.click();
      await expect.poll(async()=>Number(await cartCount.textContent()||0),{timeout:60_000}).toBeGreaterThan(previousCount);
      await expect(page.locator('[data-cart-drawer]')).toBeVisible({timeout:60_000});
      await expect(page.locator('[data-cart-lines] .cart-line').first()).toBeVisible();
    }
  });
});
