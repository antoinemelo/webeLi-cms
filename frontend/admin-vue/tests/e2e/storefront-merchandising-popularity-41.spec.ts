import { test, expect, type Page } from '@playwright/test';

const baseUrl=process.env.E2E_BASE_URL; const email=process.env.E2E_ADMIN_EMAIL; const password=process.env.E2E_ADMIN_PASSWORD;
const enabled=Boolean(baseUrl&&email&&password);
const cmsPath=(path:string):string=>{ if(!baseUrl)return path; const prefix=new URL(baseUrl).pathname.replace(/\/+$/,''); return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g,'/'); };
async function signIn(page:Page):Promise<string>{ await page.goto(cmsPath('/admin/login')); await page.getByLabel('Email').fill(email!); await page.getByRole('button',{name:/continuer/i}).click(); await page.locator('input[name="password"]').fill(password!); await page.getByRole('button',{name:/se connecter/i}).click(); await expect(page).toHaveURL(/\/admin\/app/); const response=await page.request.get(cmsPath('/admin/api/context')); return String((await response.json()).data.csrf_token); }

test.describe('41 merchandising, promotions et popularité',()=>{
  test.skip(!enabled,'Dedicated E2E environment and administrator credentials are required');

  test('configures explainable sections in Studio and renders stable public selections',async({page})=>{
    test.setTimeout(180_000);
    const csrf=await signIn(page);
    const rebuild=await page.request.post(cmsPath('/admin/api/business/pim/storefront-projections/rebuild'),{headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,'X-Contract-Version':'admin-api-v1'},data:{data:{locale:'fr'}}});
    expect(rebuild.ok(),await rebuild.text()).toBeTruthy();
    await page.goto(cmsPath('/admin/app/contents/pages/system-shop?site_id=1&language_code=fr'));
    await expect(page.getByRole('heading',{name:'Sections du Shop'})).toBeVisible();
    const promotion=page.locator('.shop-section-row').filter({hasText:'promotions'});
    await expect(promotion.getByLabel('Règle de sélection')).toBeHidden();
    await promotion.locator('.shop-section-disclosure').click();
    await expect(promotion.getByLabel('Règle de sélection')).toBeVisible();
    await promotion.getByLabel('Limite').fill('2');
    await promotion.getByLabel('Règle de sélection').selectOption('percent');
    await expect(promotion.locator('.section-explanation')).toContainText(/remise|vide/i);
    const popular=page.locator('.shop-section-row').filter({hasText:'popular'});
    await popular.locator('.shop-section-disclosure').click();
    await expect(popular.getByLabel('Fenêtre d’analyse (jours)')).toHaveValue('30');
    await page.getByRole('button',{name:'Aperçu du brouillon'}).click();
    await expect(page.getByRole('button',{name:'Ordinateur'})).toBeVisible();
    await page.getByRole('button',{name:'Mobile'}).click();
    await expect(page.locator('.shop-system-preview--mobile')).toBeVisible();
    await page.getByRole('button',{name:'Enregistrer le brouillon'}).click();
    await expect(page.getByRole('status').filter({hasText:/enregistr/i})).toBeVisible();
    await page.getByRole('button',{name:'Publier la configuration'}).click();
    await expect(page.getByRole('status').filter({hasText:/publi/i})).toBeVisible();

    for(const theme of ['default','aurora','pulse']){
      await page.goto(cmsPath(`/shop?lang=fr&_theme=${theme}`));
      await expect(page.locator('[data-shop-section="groups"]')).toBeVisible();
      await expect(page.locator('[data-shop-section="new"] [data-product-id]').first()).toBeVisible();
      await expect(page.locator('[data-shop-section="catalog"] [data-product-id]').first()).toBeVisible();
      const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>document.documentElement.clientWidth);
      expect(overflow,`${theme} has no horizontal overflow`).toBe(false);
    }
  });
});
