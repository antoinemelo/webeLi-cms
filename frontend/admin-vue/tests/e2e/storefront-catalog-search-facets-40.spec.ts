import { test, expect, type Page } from '@playwright/test';

const baseUrl=process.env.E2E_BASE_URL; const email=process.env.E2E_ADMIN_EMAIL; const password=process.env.E2E_ADMIN_PASSWORD;
const enabled=Boolean(baseUrl&&email&&password);
const cmsPath=(path:string):string=>{ if(!baseUrl)return path; const prefix=new URL(baseUrl).pathname.replace(/\/+$/,''); return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g,'/'); };
async function signIn(page:Page):Promise<string>{ await page.goto(cmsPath('/admin/login')); await page.getByLabel('Email').fill(email!); await page.getByRole('button',{name:/continuer/i}).click(); await page.locator('input[name="password"]').fill(password!); await page.getByRole('button',{name:/se connecter/i}).click(); await expect(page).toHaveURL(/\/admin\/app/); const response=await page.request.get(cmsPath('/admin/api/context')); return String((await response.json()).data.csrf_token); }
const query=(values:Record<string,string|string[]>):string=>{ const params=new URLSearchParams({lang:'fr'}); for(const [key,value] of Object.entries(values)){ for(const item of Array.isArray(value)?value:[value]) params.append(key,item); } return params.toString(); };

test.describe('40 catalogue public, facettes, recherche et tris',()=>{
  test.skip(!enabled,'Dedicated E2E environment and administrator credentials are required');

  test('keeps SSR and headless filters equivalent, shareable and usable on mobile',async({page})=>{
    test.setTimeout(240_000);
    const csrf=await signIn(page);
    const rebuild=await page.request.post(cmsPath('/admin/api/business/pim/storefront-projections/rebuild'),{headers:{'Content-Type':'application/json','X-CSRF-Token':csrf,'X-Contract-Version':'admin-api-v1'},data:{data:{locale:'fr'}}});
    expect(rebuild.ok(),await rebuild.text()).toBeTruthy();

    const groupedResponse=await page.request.get(cmsPath(`/api/v1/storefront/products?${query({'group[]':'textile',sort:'price_asc',limit:'100'})}`));
    expect(groupedResponse.ok(),await groupedResponse.text()).toBeTruthy();
    const grouped=(await groupedResponse.json()).data;
    const colour=grouped.facets.find((facet:Record<string,unknown>)=>facet.type==='attribute'&&facet.key==='couleur');
    expect(colour).toBeTruthy();
    expect(colour.options.map((option:Record<string,unknown>)=>option.key)).toEqual(expect.arrayContaining(['bleu','noir']));

    const filterQuery=query({'group[]':'textile','attributes[couleur][]':'bleu',sort:'price_asc',limit:'100'});
    const filteredResponse=await page.request.get(cmsPath(`/api/v1/storefront/products?${filterQuery}`));
    expect(filteredResponse.ok(),await filteredResponse.text()).toBeTruthy();
    const filtered=(await filteredResponse.json()).data;
    expect(filtered.pagination.total).toBeGreaterThan(0);
    expect(filtered.items.every((item:Record<string,unknown>)=>Array.isArray(item.groups)&&(item.groups as Array<Record<string,unknown>>).some(group=>group.code==='textile'))).toBeTruthy();

    await page.goto(cmsPath(`/shop?${filterQuery}`));
    await expect(page.locator('input[name="group[]"][value="textile"]')).toBeChecked();
    await expect(page.locator('input[name="attributes[couleur][]"][value="bleu"]')).toBeChecked();
    await expect(page.getByRole('status')).toContainText(`${filtered.pagination.total} résultat`);
    const ssrIds=await page.locator('[data-shop-section="catalog"] [data-product-id]').evaluateAll(nodes=>nodes.map(node=>Number(node.getAttribute('data-product-id'))));
    expect(ssrIds).toEqual(filtered.items.map((item:Record<string,unknown>)=>Number(item.product_id)));
    await expect(page.getByRole('navigation',{name:'Filtres actifs'})).toContainText('Bleu');

    const productLink=page.locator('[data-shop-section="catalog"] [data-product-id] h2 a').first();
    await productLink.click();
    const back=page.getByRole('link',{name:'Retour au catalogue'});
    await expect(back).toHaveAttribute('href',/group.*textile/);
    await back.click();
    await expect(page.locator('input[name="attributes[couleur][]"][value="bleu"]')).toBeChecked();

    const invalid=await page.request.get(cmsPath('/api/v1/storefront/products?lang=fr&sort=sql_magic'));
    expect(invalid.status()).toBe(422);

    await page.setViewportSize({width:390,height:820});
    await page.goto(cmsPath(`/shop?${filterQuery}`));
    await expect(page.getByRole('button',{name:'Afficher'})).toBeVisible();
    await page.getByRole('searchbox',{name:'Recherche'}).focus();
    await expect(page.getByRole('searchbox',{name:'Recherche'})).toBeFocused();
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>document.documentElement.clientWidth);
    expect(overflow).toBe(false);
  });
});
