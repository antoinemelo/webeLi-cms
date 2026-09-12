(() => {
  const root=document.querySelector('[data-gift-card-claim]');if(!root)return;
  const state=root.querySelector('[data-gift-card-claim-state]');const output=root.querySelector('[data-gift-card-code]');
  const token=new URLSearchParams(location.hash.slice(1)).get('claim')||'';history.replaceState(null,'',location.pathname+location.search);
  if(!token){return;}
  const base=root.dataset.basePath||'';const channel=root.dataset.channel||'web-main';const lang=root.dataset.lang||'fr';
  fetch(`${base}/api/v1/sale/channels/${encodeURIComponent(channel)}/gift-cards/claim?lang=${lang}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({data:{claim_token:token}})})
    .then(async response=>{const payload=await response.json();if(!response.ok)throw new Error(payload?.error?.message||'Gift card unavailable');return payload;})
    .then(payload=>{const card=payload.data.gift_card;state.textContent=lang==='en'?`Available balance: ${(card.balance_minor/100).toFixed(2)} ${card.currency}`:`Solde disponible : ${(card.balance_minor/100).toFixed(2)} ${card.currency}`;const code=document.createElement('code');code.textContent=card.code;output.append(code);})
    .catch(reason=>{state.textContent=reason instanceof Error?reason.message:String(reason);});
})();
