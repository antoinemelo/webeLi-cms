import { test, expect, type Page } from '@playwright/test';

const baseUrl=process.env.E2E_BASE_URL;const email=process.env.E2E_ADMIN_EMAIL;const password=process.env.E2E_ADMIN_PASSWORD;const enabled=Boolean(baseUrl&&email&&password);
function cmsPath(path:string):string{if(!baseUrl)return path;const prefix=new URL(baseUrl).pathname.replace(/\/+$/,'');return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g,'/');}
async function signIn(page:Page):Promise<void>{await page.goto(cmsPath('/admin/login'));await page.getByLabel('Email').fill(email!);await page.getByRole('button',{name:/continuer/i}).click();await page.locator('input[name="password"]').fill(password!);await page.getByRole('button',{name:/se connecter/i}).click();await expect(page).toHaveURL(/\/admin\/app(?:\/|$)/);}
const envelope=(data:unknown)=>JSON.stringify({data});

test.describe('46 commande, logistique, expédition et suivi client',()=>{
  test.skip(!enabled,'Dedicated E2E environment is required');

  test('shows partial fulfillment, safe tracking and auditable notification resend',async({page})=>{
    await signIn(page);
    const order={id:46,site_id:1,channel_id:1,channel_name:'Boutique web',order_number:'WEB-M88-0046',source:'ecommerce',status:'confirmed',payment_status:'paid',fulfillment_status:'partially_fulfilled',currency:'CHF',subtotal_minor:5800,tax_total_minor:435,shipping_total_minor:900,grand_total_minor:6700,paid_total_minor:6700,placed_at:'2026-07-17 09:00:00',customer:{first_name:'Ada',last_name:'Exemple',email:'ada@example.test'},shipping_method:{type:'shipping'},lines:[{id:1,product_name:'Gourde bleue',sku:'BOTTLE-BLUE',quantity:2,fulfilled_quantity:1,unit_price_minor:2900,currency:'CHF'}]};
    let resendCount=0;
    const dossier={order,state:{label:'Préparation en cours',next_action_label:'Poursuivre la préparation',blockers:[],actions:[]},payments:{summary:{due_minor:0,payment_proof:true},plan:null,intents:[],transactions:[],refunds:[]},fulfillment:{summary:{status:'partially_fulfilled',operations:1},backorders:[],operations:[{id:9,fulfillment_number:'FUL-M88-1',fulfillment_type:'shipping',status:'shipped',carrier_code:'swiss_post',tracking_reference:'TRACK-46',tracking_url:'https://service.post.ch/track/TRACK-46',next_action:'confirm_delivery',lines:[{id:91,order_line_id:1,product_name:'Gourde bleue',quantity:1,prepared_quantity:1}],tracking_events:[{id:1,event_type:'parcel.in_transit',event_status:'in_transit',details:{message:'Colis pris en charge'},occurred_at:'2026-07-17 11:00:00'}]}]},documents:[{id:1,document_type:'order_confirmation',document_number:'ORD-WEB-M88-0046-FR-V1',issued_at:'2026-07-17 09:00:00',printable_text:'Confirmation de commande WEB-M88-0046'}],messages:[{id:4,notification_type:'shipment_sent',delivery_status:'queued',queued_at:'2026-07-17 11:01:00'}],timeline:[],returns:[],relation:{}};
    await page.route('**/admin/api/sale/orders?**',route=>route.fulfill({status:200,contentType:'application/json',body:envelope({orders:[order]})}));
    await page.route('**/admin/api/sale/orders/46/dossier',route=>route.fulfill({status:200,contentType:'application/json',body:envelope({dossier})}));
    await page.route('**/admin/api/sale/order-notifications/4/resend',async route=>{resendCount++;await route.fulfill({status:201,contentType:'application/json',body:envelope({notification:{id:5,resend_of_id:4,status:'queued'}})});});
    await page.goto(cmsPath('/admin/app/sale/orders?order_id=46'));
    const dialog=page.getByRole('dialog',{name:'WEB-M88-0046'});
    await expect(dialog).toBeVisible();
    const tracking=dialog.getByRole('link',{name:/swiss_post.*TRACK-46/i});
    await expect(tracking).toHaveAttribute('href','https://service.post.ch/track/TRACK-46');
    await expect(tracking).toHaveAttribute('rel','noopener noreferrer');
    await expect(dialog.getByText('Colis pris en charge')).toBeVisible();
    await expect(dialog.getByText('Expédition envoyée')).toBeVisible();
    page.once('dialog',confirmation=>confirmation.accept());
    await dialog.getByRole('button',{name:'Renvoyer'}).click();
    await expect(page.getByText('Message transactionnel remis en file d’envoi.')).toBeVisible();
    expect(resendCount).toBe(1);
    await page.setViewportSize({width:390,height:844});
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>document.documentElement.clientWidth);
    expect(overflow).toBe(false);
  });
});
