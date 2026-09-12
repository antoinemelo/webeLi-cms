(() => {
  const root = document.querySelector('[data-checkout-root]');
  if (!root) return;
  const form = root.querySelector('[data-checkout-form]');
  const summary = root.querySelector('[data-checkout-summary]');
  const error = root.querySelector('[data-checkout-error]');
  const channel = root.dataset.channel || 'web-main';
  const token = root.dataset.cartToken || localStorage.getItem(`amcms.cart.${channel}`) || '';
  const lang = root.dataset.lang || 'fr';
  const base = root.dataset.basePath || '';
  const endpoint = `${base}/api/v1/sale/channels/${encodeURIComponent(channel)}`;
  let completed = false;
  let availablePaymentMethods = [];
  let selectedTestScenario = 'success_immediate';
  let retryPayment = false;
  let currentCart = null;
  const draftKey = `amcms.checkout.draft.${channel}.${token}`;
  const idempotencyKey = `amcms.checkout.idempotency.${channel}.${token}`;
  const checkoutRecordKey = `amcms.checkout.last.${channel}`;
  let attemptKey = sessionStorage.getItem(idempotencyKey) || crypto.randomUUID();
  sessionStorage.setItem(idempotencyKey, attemptKey);

  const persistDraft = () => {
    const values = {};
    for (const field of form.elements) {
      if (!field.name || field.type === 'password' || field.hasAttribute('data-sensitive')) continue;
      values[field.name] = field.type === 'checkbox' ? field.checked : field.value;
    }
    sessionStorage.setItem(draftKey, JSON.stringify(values));
  };
  const restoreDraft = () => {
    let values = {};
    try { values = JSON.parse(sessionStorage.getItem(draftKey) || '{}'); } catch (_) { values = {}; }
    for (const [name, value] of Object.entries(values)) {
      const field = form.elements.namedItem(name);
      if (!field) continue;
      if (field.type === 'checkbox') field.checked = value === true;
      else field.value = String(value ?? '');
    }
  };
  const toggleShippingAddress = () => {
    const same = form.elements.shipping_same_as_billing.checked;
    const box = form.querySelector('[data-shipping-address]');
    box.hidden = same;
    for (const field of box.querySelectorAll('input')) field.required = !same;
  };
  form.addEventListener('input', persistDraft);
  form.elements.shipping_same_as_billing.addEventListener('change', () => { toggleShippingAddress(); persistDraft(); });
  const giftInput = form.elements.gift_card_code;
  const giftStatus = form.querySelector('[data-gift-card-status]');
  form.querySelector('[data-gift-card-apply]')?.addEventListener('click', async () => {
    giftStatus.textContent = '';
    if (!giftInput.value.trim()) return;
    try {
      const payload = await readJson(await fetch(`${endpoint}/gift-cards/validate?lang=${lang}`, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({data:{cart_token:token,code:giftInput.value}})}));
      const card=payload.data.gift_card;
      giftStatus.textContent=card.valid?`${lang==='en'?'Applicable amount':'Montant applicable'}: ${(card.applicable_minor/100).toFixed(2)} ${card.currency}`:(lang==='en'?'This gift card cannot be used.':'Ce bon cadeau ne peut pas être utilisé.');
    } catch (reason) { giftStatus.textContent = reason instanceof Error ? reason.message : String(reason); }
  });

  const render = (cart) => {
    summary.textContent = '';
    for (const line of cart.lines || []) {
      const row = document.createElement('div'); row.className = 'summary-line';
      const name = document.createElement('span'); const availability=line.availability?.label||(line.availability_state==='backorder'?(lang==='en'?'Backorder':'Sur commande'):(lang==='en'?'In stock':'En stock')); name.textContent = `${line.quantity} × ${line.product_name} · ${availability}`;
      const total = document.createElement('strong'); total.textContent = `${(line.line_total_minor / 100).toFixed(2)} ${cart.currency}`;
      row.append(name, total); summary.append(row);
    }
    const shipping = document.createElement('p'); shipping.textContent = `${lang === 'en' ? 'Fulfillment' : 'Fulfillment'}: ${((cart.shipping_total_minor || 0) / 100).toFixed(2)} ${cart.currency}`; summary.append(shipping);
    const total = document.createElement('p'); total.textContent = `Total: ${(cart.grand_total_minor / 100).toFixed(2)} ${cart.currency}`; summary.append(total);
    const tax = document.createElement('p'); tax.textContent = `${lang === 'en' ? 'Taxes included' : 'Taxes incluses'}: ${(cart.tax_total_minor / 100).toFixed(2)} ${cart.currency}`; summary.append(tax);
  };
  const readJson = async (response) => { const payload = await response.json(); if (!response.ok) { const failure=new Error(payload?.error?.message || 'Checkout invalid'); failure.details=payload?.error?.details||{}; throw failure; } return payload; };
  const renderAvailabilityRecovery = (reason) => {
    if (!reason?.details?.cart_preserved) return;
    const box=document.createElement('div'); box.className='checkout-availability-recovery';
    const help=document.createElement('p'); help.textContent=lang==='en'?'Your cart is preserved. Reduce the quantity, choose another variant, order later, or remove the line.':'Votre panier est conservé. Réduisez la quantité, choisissez une autre variante, commandez ultérieurement ou retirez la ligne.';
    const cartLink=document.createElement('a'); cartLink.href=`${base}/shop`; cartLink.textContent=lang==='en'?'Return to the shop':'Retourner à la boutique';
    box.append(help,cartLink); error.append(box);
  };
  const offerAccount = (proof) => {
    if (!proof?.token) return;
    const box = document.createElement('form'); const title = document.createElement('h3');
    title.textContent = lang === 'en' ? 'Create an optional account' : 'Créer un compte (facultatif)';
    const password = document.createElement('input'); password.type = 'password'; password.minLength = 10; password.required = true; password.autocomplete = 'new-password'; password.placeholder = lang === 'en' ? 'Password (10 characters)' : 'Mot de passe (10 caractères)';
    const button = document.createElement('button'); button.textContent = lang === 'en' ? 'Create my account' : 'Créer mon compte';
    box.append(title, password, button); summary.append(box);
    box.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await readJson(await fetch(`${base}/api/v1/customer/accounts/register`, { method: 'POST', credentials: 'include', headers: {'Content-Type':'application/json'}, body: JSON.stringify({data:{proof_token:proof.token,password:password.value}}) }));
        location.href = `${base}/account`;
      } catch (reason) { error.textContent = reason instanceof Error ? reason.message : String(reason); }
    });
  };
  const load = async () => {
    if (!token) { error.textContent = lang === 'en' ? 'Missing cart token.' : 'Token de panier manquant.'; return; }
    const [payload, bootstrap] = await Promise.all([
      readJson(await fetch(`${endpoint}/cart/${encodeURIComponent(token)}?lang=${lang}`, { headers: { Accept: 'application/json' } })),
      readJson(await fetch(`${endpoint}/bootstrap?lang=${lang}`, { headers: { Accept: 'application/json' } })),
    ]);
    currentCart = payload.data.cart;
    const select = form.elements.shipping_method; select.textContent = '';
    for (const method of bootstrap.data.fulfillment_methods || []) { const option = document.createElement('option'); option.value = method.code; option.textContent = `${method.label}${method.flat_rate_minor ? ` — ${(method.flat_rate_minor / 100).toFixed(2)} ${bootstrap.data.channel.currency}` : ''}`; select.append(option); }
    availablePaymentMethods = bootstrap.data.payment_methods || [];
    if (availablePaymentMethods.some((method) => method.test_mode)) {
      const badge = document.createElement('div'); badge.className = 'checkout-test-mode'; badge.setAttribute('role', 'status'); badge.textContent = `MODE TEST — ${lang === 'en' ? 'No production payment' : 'Aucun paiement de production'}`; root.prepend(badge);
    }
    const paymentSelect = form.elements.payment_method; paymentSelect.textContent = '';
    for (const method of availablePaymentMethods) { const option = document.createElement('option'); option.value = method.code; option.textContent = method.label; option.dataset.nextAction = method.next_action || ''; paymentSelect.append(option); }
    const developer = document.createElement('details'); developer.dataset.testScenarios = ''; developer.hidden = true; const legend = document.createElement('summary'); legend.textContent = lang === 'en' ? 'Developer scenarios' : 'Scénarios développeur'; const scenarios = document.createElement('select');
    developer.append(legend, scenarios); paymentSelect.closest('label')?.append(developer);
    const refreshScenarios = () => { const method = availablePaymentMethods.find((item) => item.code === paymentSelect.value); developer.hidden = !method?.test_mode; scenarios.textContent = ''; for (const scenario of method?.scenarios || []) { const option=document.createElement('option'); option.value=scenario; option.textContent=scenario.replaceAll('_',' '); scenarios.append(option); } selectedTestScenario=scenarios.value||'success_immediate'; };
    paymentSelect.addEventListener('change', refreshScenarios); scenarios.addEventListener('change',()=>{selectedTestScenario=scenarios.value;}); refreshScenarios();
    const identity = currentCart.identity || {}; const billing = currentCart.billing_address || {};
    for (const name of ['first_name','last_name','email','phone']) if (identity[name] && !form.elements[name].value) form.elements[name].value = identity[name];
    for (const name of ['line1','postal_code','city','country_code']) if (billing[name] && !form.elements[name].value) form.elements[name].value = billing[name];
    restoreDraft(); toggleShippingAddress();
    const policyRoot = form.querySelector('[data-order-policy]');
    const onOrder = (currentCart.lines || []).some((line) => line.availability_state === 'backorder' || Number(line.reservation?.backorder_quantity || 0) > 0);
    const timings = bootstrap.data.checkout?.payment_timing || [];
    if (onOrder && timings.includes('prepaid') && timings.includes('when_available')) {
      const days = Math.max(1, ...(currentCart.lines || []).map((line) => Number(line.reservation?.delivery_lead_time_days || 0)));
      policyRoot.innerHTML = `<fieldset class="checkout-order-policy"><legend>${lang === 'en' ? 'On-order payment' : 'Paiement d’un article sur commande'}</legend><p>${lang === 'en' ? `Expected lead time: about ${days} days. Price is fixed when the order is placed; no final invoice is issued before payment.` : `Délai indicatif : environ ${days} jours. Le prix est fixé à la commande ; aucune facture finale n’est émise avant paiement.`}</p><label><input type="radio" name="payment_timing" value="prepaid" checked> ${lang === 'en' ? 'Pay now' : 'Payer maintenant'}</label><label><input type="radio" name="payment_timing" value="when_available"> ${lang === 'en' ? 'Pay when available' : 'Payer lorsque disponible'}</label><label class="check" data-deferred-terms hidden><input type="checkbox" name="order_policy_terms"> ${lang === 'en' ? 'I accept the stated lead time, price and payment window.' : 'J’accepte le délai, le prix et la fenêtre de paiement indiqués.'}</label></fieldset>`;
      const syncPolicy = () => { const deferred = form.elements.payment_timing.value === 'when_available'; const terms = form.elements.order_policy_terms; terms.closest('[data-deferred-terms]').hidden = !deferred; terms.required = deferred; persistDraft(); };
      policyRoot.addEventListener('change', syncPolicy); restoreDraft(); syncPolicy();
    }
    if (!completed) render(currentCart);
  };
  form.addEventListener('submit', async (event) => {
    event.preventDefault(); error.textContent = '';
    if (!form.reportValidity()) return;
    const submit=form.querySelector('button[type="submit"]'); if(submit?.disabled)return; if(submit){submit.disabled=true;submit.setAttribute('aria-busy','true');}
    const values = new FormData(form);
    const data = {
      cart_token: token,
      identity: { email: values.get('email'), first_name: values.get('first_name'), last_name: values.get('last_name'), phone: values.get('phone') },
      billing_address: { line1: values.get('line1'), postal_code: values.get('postal_code'), city: values.get('city'), country_code: String(values.get('country_code')).toUpperCase() },
      shipping_same_as_billing: values.get('shipping_same_as_billing') === 'on',
      shipping_address: values.get('shipping_same_as_billing') === 'on' ? {
        line1: values.get('line1'), postal_code: values.get('postal_code'), city: values.get('city'), country_code: String(values.get('country_code')).toUpperCase(),
      } : {
        line1: values.get('shipping_line1'), postal_code: values.get('shipping_postal_code'), city: values.get('shipping_city'), country_code: String(values.get('shipping_country_code')).toUpperCase(),
      },
      shipping_method: { code: values.get('shipping_method') }, payment: { code: values.get('payment_method'), scenario: selectedTestScenario },
      terms_accepted: values.get('terms_accepted') === 'on', marketing_consent: values.get('marketing_consent') === 'on',
      expected_version: currentCart?.version,
      gift_card: giftInput.value.trim() ? { code: giftInput.value.trim() } : undefined,
    };
    if (values.get('payment_timing') === 'when_available') {
      const days = Math.max(1, ...(currentCart?.lines || []).map((line) => Number(line.reservation?.delivery_lead_time_days || 0)));
      const availability = new Date(Date.now() + days * 86400000);
      data.order_policy = { payment_timing: 'when_available', price_policy: 'frozen', deposit_minor: 0, expected_availability_at: availability.toISOString(), payment_window_seconds: 604800, terms_accepted: values.get('order_policy_terms') === 'on' };
    }
    try {
      const actionUrl = retryPayment ? `${endpoint}/cart/${encodeURIComponent(token)}/payment-retry?lang=${lang}` : `${endpoint}/checkout?lang=${lang}`;
      const payload = await readJson(await fetch(actionUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': attemptKey }, body: JSON.stringify({ data }) }));
      completed = true; summary.textContent = '';
      const title = document.createElement('h3'); title.textContent = `${lang === 'en' ? 'Order' : 'Commande'} ${payload.data.order.order_number}`; summary.append(title);
      const payment = payload.data.payment;
      if (payload.data.gift_card?.amount_minor) { const applied=document.createElement('p'); applied.textContent=`${lang==='en'?'Gift card used':'Bon cadeau utilisé'}: ${(payload.data.gift_card.amount_minor/100).toFixed(2)} ${payload.data.order.currency}`; summary.append(applied); }
      const recoverableFailure = payment?.state?.recoverable && payment.status === 'failed';
      if (!recoverableFailure) {
        localStorage.removeItem(`amcms.cart.${channel}`);
        sessionStorage.removeItem(draftKey); sessionStorage.removeItem(idempotencyKey);
      }
      sessionStorage.setItem(checkoutRecordKey, JSON.stringify({ order_number: payload.data.order.order_number, payment_reference: payment?.reference || null, recorded_at: new Date().toISOString() }));
      const selectedMethod = availablePaymentMethods.find((method) => method.code === String(values.get('payment_method')));
      const state = document.createElement('p');
      state.textContent = payment?.state?.label || selectedMethod?.description || (lang === 'en' ? 'Order recorded.' : 'Commande enregistrée.'); summary.append(state);
      if (payment?.checkout_url) {
        const action = document.createElement('a'); action.className = 'checkout-payment-action'; action.href = payment.checkout_url.startsWith('http') ? payment.checkout_url : `${base}${payment.checkout_url}`;
        action.textContent = lang === 'en' ? 'Continue payment' : 'Continuer le paiement'; summary.append(action);
      } else if (selectedMethod?.next_action && selectedMethod.next_action !== 'none') {
        const next = document.createElement('small'); next.textContent = `${lang === 'en' ? 'Next step' : 'Prochaine étape'}: ${selectedMethod.next_action}`; summary.append(next);
      }
      if (payment?.instructions) {
        const instructions=document.createElement('section'); instructions.className='checkout-bank-instructions';
        for (const key of ['beneficiary','iban','amount_minor','currency','reference','expected_delay']) { const row=document.createElement('p'); const value=key==='amount_minor'?(payment.instructions[key]/100).toFixed(2):String(payment.instructions[key]||''); const label=document.createElement('b'); label.textContent=`${key.replaceAll('_',' ')}: `; const text=document.createElement('span'); text.textContent=value; const copy=document.createElement('button'); copy.type='button'; copy.textContent=lang==='en'?'Copy':'Copier'; copy.addEventListener('click',()=>navigator.clipboard.writeText(value)); row.append(label,text,copy); instructions.append(row); }
        const print=document.createElement('button'); print.type='button'; print.textContent=lang==='en'?'Print':'Imprimer'; print.addEventListener('click',()=>window.print());
        const download=document.createElement('button'); download.type='button'; download.textContent=lang==='en'?'Download':'Télécharger'; download.addEventListener('click',()=>{const url=URL.createObjectURL(new Blob([payment.instructions.printable_text||''],{type:'text/plain'}));const link=document.createElement('a');link.href=url;link.download=`${payment.instructions.reference||'bank-transfer'}.txt`;link.click();URL.revokeObjectURL(url);}); instructions.append(print,download); summary.append(instructions);
      }
      for (const issued of payment?.gift_cards || []) {
        const delivery=issued.delivery;if(!delivery?.claim_token)continue;
        const box=document.createElement('section');const text=document.createElement('p');text.textContent=lang==='en'?'Your gift card is ready. Its code can be displayed once.':'Votre bon cadeau est prêt. Son code peut être affiché une seule fois.';
        const reveal=document.createElement('button');reveal.type='button';reveal.textContent=lang==='en'?'Display the gift card':'Afficher le bon cadeau';
        reveal.addEventListener('click',async()=>{reveal.disabled=true;try{const claimed=await readJson(await fetch(`${endpoint}/gift-cards/claim?lang=${lang}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({data:{claim_token:delivery.claim_token}})}));const code=document.createElement('code');code.textContent=claimed.data.gift_card.code;box.append(code);reveal.remove();}catch(reason){text.textContent=reason instanceof Error?reason.message:String(reason);reveal.disabled=false;}});box.append(text,reveal);summary.append(box);
      }
      if (payment?.test_mode && payment?.test_token && payment?.reference && ['requires_action','authorized'].includes(payment.status)) {
        const simulation=document.createElement('button'); simulation.type='button'; simulation.textContent=lang==='en'?'Run test scenario':'Exécuter le scénario test'; const result=document.createElement('pre');
        simulation.addEventListener('click',async()=>{simulation.disabled=true;try{const response=await readJson(await fetch(`${base}/api/v1/sale/payments/test/${encodeURIComponent(payment.reference)}/simulate?lang=${lang}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({data:{test_token:payment.test_token,outcome:payment.scenario||selectedTestScenario,deliver_webhook:true}})}));const intent=response.data.webhook?.intent;result.textContent=`${response.data.provider_status}${intent?.state?.label?` — ${intent.state.label}`:''}${response.data.webhook?.duplicate_delivery?.duplicate?' — duplicate ignoré':''}${response.data.webhook?.ignored_out_of_order?' — événement hors ordre ignoré':''}`;}catch(reason){result.textContent=reason instanceof Error?reason.message:String(reason);}finally{simulation.disabled=false;}}); summary.append(simulation,result);
      }
      if (payment?.state?.recoverable && payment.status === 'failed') { const retry=document.createElement('button'); retry.type='button'; retry.textContent=lang==='en'?'Try again or choose another method':'Réessayer ou choisir un autre moyen'; retry.addEventListener('click',()=>{retryPayment=true;completed=false;attemptKey=crypto.randomUUID();sessionStorage.setItem(idempotencyKey,attemptKey);form.hidden=false;summary.textContent='';}); summary.append(retry); }
      form.hidden = true; offerAccount(payload.data.account_creation);
    } catch (reason) { error.textContent = reason instanceof Error ? reason.message : String(reason); renderAvailabilityRecovery(reason); }
    finally { if(submit&&!completed){submit.disabled=false;submit.removeAttribute('aria-busy');} }
  });
  load().catch((reason) => { error.textContent = reason instanceof Error ? reason.message : String(reason); });
})();
