import { test, expect, type Page } from '@playwright/test';

const baseUrl=process.env.E2E_BASE_URL;
const email=process.env.E2E_ADMIN_EMAIL;
const password=process.env.E2E_ADMIN_PASSWORD;
const enabled=Boolean(baseUrl&&email&&password);
const cmsPath=(path:string):string=>{if(!baseUrl)return path;const prefix=new URL(baseUrl).pathname.replace(/\/+$/,'');return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g,'/');};

async function prepare(page:Page):Promise<Record<string,any>>{
  await page.goto(cmsPath('/admin/login'));
  await page.getByLabel('Email').fill(email!);
  await page.getByRole('button',{name:/continuer/i}).click();
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button',{name:/se connecter/i}).click();
  const context=await page.request.get(cmsPath('/admin/api/context'));
  const csrf=String((await context.json()).data.csrf_token);
  const rebuild=await page.request.post(cmsPath('/admin/api/business/pim/storefront-projections/rebuild'),{headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,'X-Contract-Version':'admin-api-v1'},data:{data:{locale:'fr'}}});
  expect(rebuild.ok(),await rebuild.text()).toBeTruthy();
  const response=await page.request.get(cmsPath('/api/v1/storefront/products?lang=fr&limit=100'));
  expect(response.ok(),await response.text()).toBeTruthy();
  const products=(await response.json()).data.items as Array<Record<string,any>>;
  return products.find(item=>item.cta&&Array.isArray(item.sellables)&&item.sellables.filter((sellable:any)=>sellable.orderable).length>1)||products.find(item=>item.cta);
}

test.describe('Modern storefront preview and cart',()=>{
  test.skip(!enabled,'Dedicated E2E storefront environment is required');

  test('overlays variant availability and shares a responsive cart experience',async({page},testInfo)=>{
    test.setTimeout(180_000);
    const product=await prepare(page);
    expect(product).toBeTruthy();
    await page.setViewportSize({width:1440,height:1000});
    await page.goto(cmsPath('/shop?lang=fr&_theme=default'));
    await page.evaluate(()=>localStorage.removeItem('amcms.cart.web-main'));
    await page.reload();

    const card=page.locator(`[data-product-id="${product.product_id}"]`).first();
    await expect(card).toBeVisible();
    const preview=card.locator('[data-product-preview]');
    const sheet=preview.locator('.storefront-card__preview-body');
    const selectionStatus=card.locator('[data-variant-selection-status]');
    if(product.sellables.length>1)await selectionStatus.hover();else await card.locator('[data-product-preview-trigger]').first().hover();
    await expect(sheet).toBeVisible();
    const desktopStyle=await sheet.evaluate(element=>{const style=getComputedStyle(element);return{position:style.position,opacity:style.opacity,pointerEvents:style.pointerEvents};});
    expect(desktopStyle).toEqual({position:'absolute',opacity:'1',pointerEvents:'auto'});
    await expect(sheet.locator('[data-stock-state]')).toHaveCount(product.sellables.length);
    expect(await sheet.locator('[data-stock-state]').evaluateAll(nodes=>nodes.every(node=>['available','low','unavailable'].includes(String((node as HTMLElement).dataset.stockState))))).toBe(true);
    if(!['physical','bundle'].includes(String(product.type)))await expect(sheet.getByText('Stock faible',{exact:true})).toHaveCount(0);
    const viewAction=card.getByRole('link',{name:'Voir',exact:true});
    const addAction=card.locator('[data-storefront-add-to-cart]');
    let expectedCartLines=1;
    await expect(viewAction).toBeVisible();
    await viewAction.click({trial:true});
    if(product.sellables.length>1){
      await expect(addAction).toBeDisabled();
      const choices=sheet.locator('[data-product-variant-choice]:not([disabled])');
      const choice=choices.first();
      await choice.click();await expect(choice).toHaveAttribute('aria-pressed','true');
      await choice.click();await expect(choice).toHaveAttribute('aria-pressed','false');await expect(addAction).toBeDisabled();
      await choice.click();
      if(await choices.count()>1)await choices.nth(1).click();
      expectedCartLines=Math.min(2,await choices.count());
      await expect(addAction).toBeEnabled();
      await expect(addAction).toHaveAttribute('data-sellable-ids',await choices.evaluateAll(nodes=>nodes.slice(0,2).map(node=>(node as HTMLElement).dataset.sellableId).join(',')));
    }
    await addAction.click({trial:true});
    await page.screenshot({path:testInfo.outputPath('product-preview-desktop.png'),fullPage:false});
    await page.keyboard.press('Escape');

    await page.setViewportSize({width:390,height:844});
    await page.reload();
    if(product.sellables.length>1)await selectionStatus.click();else await card.locator('[data-product-preview-trigger]').first().click();
    await expect(preview).toHaveAttribute('open','');
    await expect(sheet).toBeVisible();
    expect(await sheet.evaluate(element=>getComputedStyle(element).position)).toBe('fixed');
    await expect(page.locator('html')).toHaveClass(/product-preview-is-open/);
    if(product.sellables.length>1){
      await expect(addAction).toBeDisabled();
      const mobileChoices=sheet.locator('[data-product-variant-choice]:not([disabled])');
      const mobileChoice=mobileChoices.first();
      await mobileChoice.click();await expect(mobileChoice).toHaveAttribute('aria-pressed','true');
      await mobileChoice.click();await expect(mobileChoice).toHaveAttribute('aria-pressed','false');await expect(addAction).toBeDisabled();
      await mobileChoice.click();
      if(await mobileChoices.count()>1)await mobileChoices.nth(1).click();
      expectedCartLines=Math.min(2,await mobileChoices.count());
      await expect(preview).toHaveAttribute('open','');
      await expect(addAction).toBeEnabled();
      await expect(addAction).toHaveAttribute('data-sellable-ids',await mobileChoices.evaluateAll(nodes=>nodes.slice(0,2).map(node=>(node as HTMLElement).dataset.sellableId).join(',')));
      await preview.locator('summary').click();
      await expect(preview).not.toHaveAttribute('open','');
    }else{
      await preview.locator('summary').click();
      await expect(preview).not.toHaveAttribute('open','');
    }

    await card.locator('[data-storefront-add-to-cart]').click();
    const drawer=page.locator('[data-cart-drawer]');
    await expect(drawer).toBeVisible({timeout:30_000});
    await expect(drawer.locator('.cart-drawer__header')).toBeVisible();
    await expect(drawer.locator('.cart-drawer__footer')).toBeVisible();
    await expect(drawer.locator('[data-cart-lines] .cart-line')).toHaveCount(expectedCartLines);
    await expect(drawer.locator('[data-cart-increase]').first()).toBeVisible();
    await expect(drawer.locator('[data-cart-decrease]').first()).toBeDisabled();
    await page.screenshot({path:testInfo.outputPath('cart-drawer-mobile.png'),fullPage:false});

    await page.setViewportSize({width:1440,height:1000});
    await drawer.locator('[data-cart-full]').click();
    await expect(page).toHaveURL(/\/cart(?:\?|$)/);
    await expect(page.locator('.cart-page__layout')).toBeVisible();
    await expect(page.locator('.cart-page__summary')).toBeVisible();
    await expect(page.locator('[data-cart-lines] .cart-line')).toHaveCount(expectedCartLines);
    const hasSidebar=await page.locator('.cart-page__layout').evaluate(element=>{
      const content=element.querySelector('.cart-page__items')?.getBoundingClientRect();
      const summary=element.querySelector('.cart-page__summary')?.getBoundingClientRect();
      return Boolean(content&&summary&&summary.left>content.right);
    });
    expect(hasSidebar).toBe(true);
    await page.screenshot({path:testInfo.outputPath('cart-page-desktop.png'),fullPage:false});
  });
});
