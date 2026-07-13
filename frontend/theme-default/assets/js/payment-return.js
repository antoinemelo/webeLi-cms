(() => {
  const root=document.querySelector('[data-payment-return]'); if(!root)return;
  const state=root.querySelector('[data-payment-return-state]'); const base=root.dataset.basePath||''; const lang=root.dataset.lang||'fr';
  const provider=root.dataset.provider||''; const reference=root.dataset.reference||'';
  const run=async()=>{try{const response=await fetch(base+'/api/v1/sale/payments/return?provider='+encodeURIComponent(provider)+'&reference='+encodeURIComponent(reference)+'&lang='+lang,{headers:{Accept:'application/json'}});const payload=await response.json();if(!response.ok)throw new Error(payload?.error?.message||'Payment status unavailable');const intent=payload.data?.intent;state.textContent=intent?.state?.label||(lang==='en'?'Payment is still being confirmed by the server.':'Le paiement est encore en cours de confirmation par le serveur.');state.dataset.status=intent?.status||'unknown';}catch(error){state.textContent=lang==='en'?'The status could not be refreshed. You can safely reload this page.':'Le statut n’a pas pu être actualisé. Vous pouvez recharger cette page sans risque.';}}; run();
})();
