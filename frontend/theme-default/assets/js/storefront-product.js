(()=>{'use strict';
const money=(minor,currency)=>new Intl.NumberFormat(document.documentElement.lang||'fr',{style:'currency',currency:currency||'CHF'}).format(Number(minor||0)/100);
const en=(document.documentElement.lang||'fr').toLowerCase().startsWith('en');
const media=(root,url,type,alt,caption='')=>{if(!url)return;const target=root.querySelector('[data-product-main-media]');if(!target)return;const captionNode=target.querySelector('[data-product-media-caption]');target.replaceChildren();const node=document.createElement(type==='video'?'video':'img');node.src=url;if(type==='video'){node.controls=true;node.preload='metadata';node.setAttribute('aria-label',alt||(en?'Product video':'Vidéo du produit'))}else{node.alt=alt||'';node.width=1000;node.height=750}target.append(node);const output=captionNode||document.createElement('p');output.dataset.productMediaCaption='';output.textContent=caption;target.append(output)};
document.querySelectorAll('[data-product-detail]').forEach(root=>{
  const select=root.querySelector('[data-product-variant]');const add=root.querySelector('[data-product-add]');const qty=root.querySelector('[data-product-quantity]');
  const update=()=>{if(!select)return;const option=select.selectedOptions[0];const d=option.dataset;root.querySelector('[data-product-sku]').textContent=d.sku||'—';root.querySelector('[data-product-price]').innerHTML=`${Number(d.regular)>Number(d.final)?`<del>${money(d.regular,d.currency)}</del> `:''}<strong>${money(d.final,d.currency)}</strong>`;root.querySelector('[data-product-discount]').textContent=Number(d.discount)>0?`Promotion −${Math.round(Number(d.discount)/100)}%`:'';const availability=root.querySelector('[data-product-availability]');availability.querySelector('[data-product-availability-label]').textContent=d.availability||(en?'Unavailable':'Indisponible');availability.querySelector('[data-product-availability-icon]').textContent=({available:'●',last_available:'◐',on_order:'◷',unavailable:'○'})[d.status]||'○';availability.className=`storefront-availability storefront-availability--${d.tone||'neutral'}`;root.querySelector('[data-product-delay]').textContent=Number(d.delay)>0?(en?`Lead time: ${d.delay} days`:`Délai : ${d.delay} jours`):'';const orderable=d.orderable==='1';add.dataset.sellableId=option.value;add.disabled=!orderable;add.hidden=!orderable;media(root,d.mediaUrl,d.mediaType,d.mediaAlt,d.mediaCaption);const url=new URL(location.href);url.searchParams.set('variant',option.value);history.replaceState({},'',url);root.querySelector('[data-product-announcement]').textContent=`${en?'Variant':'Variante'} ${option.textContent.trim()}. ${d.availability}. ${money(d.final,d.currency)}.`};
  if(select){const requested=new URL(location.href).searchParams.get('variant');if(requested&&[...select.options].some(option=>option.value===requested)){select.value=requested;update()}select.addEventListener('change',update)}qty?.addEventListener('input',()=>{add.dataset.quantity=String(Math.max(1,Number(qty.value)||1))});root.querySelectorAll('[data-product-media]').forEach(button=>button.addEventListener('click',()=>media(root,button.dataset.mediaUrl,button.dataset.mediaType,button.dataset.mediaAlt,button.dataset.mediaCaption)));
});
const hoverPreview=matchMedia('(hover: hover) and (min-width: 48rem)');
document.querySelectorAll('.storefront-card').forEach(card=>{
  const preview=card.querySelector('[data-product-preview]');if(!preview)return;
  const add=card.querySelector('[data-storefront-add-to-cart]');const status=card.querySelector('[data-variant-selection-status]');
  const emptyStatus=status?.textContent||'';
  card.querySelectorAll('[data-product-preview-trigger]').forEach(trigger=>{
    trigger.addEventListener('mouseenter',()=>{if(!hoverPreview.matches||preview.dataset.suppressHover==='1')return;preview.dataset.openedByHover='1';preview.open=true});
    trigger.addEventListener('click',event=>{if(hoverPreview.matches&&event.detail!==0)return;event.preventDefault();delete preview.dataset.openedByHover;preview.open=true});
  });
  card.querySelectorAll('[data-product-variant-choice]').forEach(choice=>choice.addEventListener('click',()=>{
    if(choice.disabled||!add)return;
    const selected=choice.getAttribute('aria-pressed')!=='true';choice.setAttribute('aria-pressed',String(selected));choice.closest('.storefront-card__variant')?.classList.toggle('is-selected',selected);
    const choices=[...card.querySelectorAll('[data-product-variant-choice][aria-pressed="true"]')];
    const ids=choices.map(item=>item.dataset.sellableId).filter(Boolean);delete add.dataset.sellableId;
    if(ids.length){add.dataset.sellableIds=ids.join(',');add.disabled=false;add.setAttribute('aria-disabled','false')}
    else{delete add.dataset.sellableIds;add.disabled=true;add.setAttribute('aria-disabled','true')}
    if(status){status.textContent=ids.length?(en?(ids.length===1?'1 option selected. You can add it to the cart.':`${ids.length} options selected. You can add them to the cart.`):(ids.length===1?'1 option sélectionnée. Vous pouvez l’ajouter au panier.':`${ids.length} options sélectionnées. Vous pouvez les ajouter au panier.`)):emptyStatus;status.classList.toggle('is-confirmed',ids.length>0)}
  }));
  card.addEventListener('mouseleave',()=>{delete preview.dataset.suppressHover;if(preview.dataset.openedByHover!=='1')return;delete preview.dataset.openedByHover;preview.open=false});
});
document.addEventListener('click',async event=>{const copy=event.target.closest('[data-copy-product-url]');if(!copy)return;try{await navigator.clipboard.writeText(copy.dataset.url||location.href);const status=copy.parentElement.querySelector('[data-copy-confirmation]');if(status)status.textContent=en?'Link copied.':'Lien copié.'}catch{location.hash='product-title'}});
const syncPreviewLock=()=>document.documentElement.classList.toggle('product-preview-is-open',Boolean(document.querySelector('[data-product-preview][open]'))&&matchMedia('(hover: none), (max-width: 47.99rem)').matches);
document.addEventListener('toggle',event=>{const preview=event.target.closest?.('[data-product-preview]');if(!preview)return;if(preview.open)document.querySelectorAll('[data-product-preview][open]').forEach(other=>{if(other!==preview){delete other.dataset.openedByHover;other.open=false}});syncPreviewLock()},true);
document.addEventListener('click',event=>{const opened=document.querySelector('[data-product-preview][open]');if(opened&&event.target===opened){opened.open=false;opened.querySelector('summary')?.focus();syncPreviewLock()}});
document.addEventListener('keydown',event=>{if(event.key!=='Escape')return;document.querySelectorAll('[data-product-preview][open]').forEach(details=>{if(hoverPreview.matches)details.dataset.suppressHover='1';delete details.dataset.openedByHover;details.open=false;details.querySelector('summary')?.focus()});syncPreviewLock()});
})();
