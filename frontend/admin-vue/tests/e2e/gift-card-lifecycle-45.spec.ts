import { test, expect, type Page } from '@playwright/test';

const baseUrl=process.env.E2E_BASE_URL;const email=process.env.E2E_ADMIN_EMAIL;const password=process.env.E2E_ADMIN_PASSWORD;const enabled=Boolean(baseUrl&&email&&password);
function cmsPath(path:string):string{if(!baseUrl)return path;const prefix=new URL(baseUrl).pathname.replace(/\/+$/,'');return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g,'/');}
async function signIn(page:Page):Promise<void>{await page.goto(cmsPath('/admin/login'));await page.getByLabel('Email').fill(email!);await page.getByRole('button',{name:/continuer/i}).click();await page.locator('input[name="password"]').fill(password!);await page.getByRole('button',{name:/se connecter/i}).click();await expect(page).toHaveURL(/\/admin\/app(?:\/|$)/);}
const envelope=(data:unknown)=>JSON.stringify({data});

test.describe('45 bons cadeaux cycle de vie',()=>{
  test.skip(!enabled,'Dedicated E2E environment is required');

  test('keeps the back-office masked and exposes the immutable ledger',async({page})=>{
    await signIn(page);
    const card={id:7,public_reference:'GFT-DEMO45',masked_code:'•••• 7K2P',initial_value_minor:10000,balance_minor:6500,currency:'CHF',status:'active',origin_order_id:41,ledger:[{id:1,entry_type:'issuance',amount_delta_minor:10000,created_at:'2026-07-17 10:00:00'},{id:2,entry_type:'debit',amount_delta_minor:-3500,created_at:'2026-07-17 11:00:00'}]};
    await page.route('**/admin/api/sale/gift-cards*',route=>route.fulfill({status:200,contentType:'application/json',body:envelope({gift_cards:[card]})}));
    await page.route('**/admin/api/sale/gift-cards/7',route=>route.fulfill({status:200,contentType:'application/json',body:envelope({gift_card:card})}));
    await page.goto(cmsPath('/admin/app/sale/gift-cards'));
    await expect(page.getByRole('heading',{name:'Bons cadeaux'})).toBeVisible();
    await expect(page.getByText('•••• 7K2P')).toBeVisible();
    await expect(page.getByText(/GC-[A-Z0-9]{4}/)).toHaveCount(0);
    await page.getByRole('button',{name:/GFT-DEMO45/}).click();
    await expect(page.getByRole('heading',{name:'Journal immuable'})).toBeVisible();
    await expect(page.getByRole('dialog')).toContainText(/65[,.]00/);
  });

  test('reveals a delivered code once from a fragment that is removed immediately',async({page})=>{
    await page.route('**/api/v1/sale/channels/web-main/gift-cards/claim?**',route=>route.fulfill({status:200,contentType:'application/json',body:envelope({gift_card:{code:'GC-ABCD-EFGH-IJKL-MNOP-QRST-UVWX-YZ12',balance_minor:10000,currency:'CHF',status:'active'}})}));
    await page.goto(cmsPath('/gift-card?channel=web-main&lang=fr#claim=opaque-one-time-token'));
    await expect(page.getByRole('heading',{name:'Votre bon cadeau'})).toBeVisible();
    await expect(page.locator('code')).toHaveText('GC-ABCD-EFGH-IJKL-MNOP-QRST-UVWX-YZ12');
    await expect(page).not.toHaveURL(/opaque-one-time-token/);
  });
});
