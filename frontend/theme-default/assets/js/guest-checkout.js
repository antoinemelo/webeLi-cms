(() => {
  const root = document.querySelector('[data-checkout-root]');
  if (!root) return;
  const form = root.querySelector('[data-checkout-form]');
  const summary = root.querySelector('[data-checkout-summary]');
  const error = root.querySelector('[data-checkout-error]');
  const channel = root.dataset.channel || 'web-main';
  const token = root.dataset.cartToken || localStorage.getItem(`amcms-sale-cart:${channel}`) || '';
  const lang = root.dataset.lang || 'fr';
  const base = root.dataset.basePath || '';
  const endpoint = `${base}/api/v1/sale/channels/${encodeURIComponent(channel)}`;
  let completed = false;
  let availablePaymentMethods = [];
  let selectedTestScenario = 'success_immediate';
  let retryPayment = false;

  const render = (cart) => {
    summary.textContent = '';
    for (const line of cart.lines || []) {
      const row = document.createElement('div'); row.className = 'summary-line';
      const name = document.createElement('span'); name.textContent = `${line.quantity} × ${line.product_name}`;
      const total = document.createElement('strong'); total.textContent = `${(line.line_total_minor / 100).toFixed(2)} ${cart.currency}`;
      row.append(name, total); summary.append(row);
    }
    const tax = document.createElement('p'); tax.textContent = `${lang === 'en' ? 'Tax' : 'TVA'}: ${(cart.tax_total_minor / 100).toFixed(2)} ${cart.currency}`; summary.append(tax);
    const shipping = document.createElement('p'); shipping.textContent = `${lang === 'en' ? 'Fulfillment' : 'Fulfillment'}: ${((cart.shipping_total_minor || 0) / 100).toFixed(2)} ${cart.currency}`; summary.append(shipping);
    const total = document.createElement('p'); total.textContent = `Total: ${(cart.grand_total_minor / 100).toFixed(2)} ${cart.currency}`; summary.append(total);
  };
  const readJson = async (response) => { const payload = await response.json(); if (!response.ok) throw new Error(payload?.error?.message || 'Checkout invalid'); return payload; };
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
    if (!completed) render(payload.data.cart);
  };
  form.addEventListener('submit', async (event) => {
    event.preventDefault(); error.textContent = '';
    if (!form.reportValidity()) return;
    const values = new FormData(form);
    const data = {
      cart_token: token,
      identity: { email: values.get('email'), first_name: values.get('first_name'), last_name: values.get('last_name'), phone: values.get('phone') },
      billing_address: { line1: values.get('line1'), postal_code: values.get('postal_code'), city: values.get('city'), country_code: String(values.get('country_code')).toUpperCase() },
      shipping_same_as_billing: values.get('shipping_same_as_billing') === 'on',
      shipping_method: { code: values.get('shipping_method') }, payment: { code: values.get('payment_method'), scenario: selectedTestScenario },
      terms_accepted: values.get('terms_accepted') === 'on', marketing_consent: values.get('marketing_consent') === 'on',
    };
    try {
      const key = crypto.randomUUID();
      const actionUrl = retryPayment ? `${endpoint}/cart/${encodeURIComponent(token)}/payment-retry?lang=${lang}` : `${endpoint}/checkout?lang=${lang}`;
      const payload = await readJson(await fetch(actionUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': key }, body: JSON.stringify({ data }) }));
      completed = true; summary.textContent = '';
      const title = document.createElement('h3'); title.textContent = `${lang === 'en' ? 'Order' : 'Commande'} ${payload.data.order.order_number}`; summary.append(title);
      const payment = payload.data.payment;
      if (!payment || payment.status === 'captured') localStorage.removeItem(`amcms-sale-cart:${channel}`);
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
      if (payment?.test_mode && payment?.test_token && payment?.reference && ['requires_action','authorized'].includes(payment.status)) {
        const simulation=document.createElement('button'); simulation.type='button'; simulation.textContent=lang==='en'?'Run test scenario':'Exécuter le scénario test'; const result=document.createElement('pre');
        simulation.addEventListener('click',async()=>{simulation.disabled=true;try{const response=await readJson(await fetch(`${base}/api/v1/sale/payments/test/${encodeURIComponent(payment.reference)}/simulate?lang=${lang}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({data:{test_token:payment.test_token,outcome:payment.scenario||selectedTestScenario,deliver_webhook:true}})}));const intent=response.data.webhook?.intent;result.textContent=`${response.data.provider_status}${intent?.state?.label?` — ${intent.state.label}`:''}${response.data.webhook?.duplicate_delivery?.duplicate?' — duplicate ignoré':''}${response.data.webhook?.ignored_out_of_order?' — événement hors ordre ignoré':''}`;}catch(reason){result.textContent=reason instanceof Error?reason.message:String(reason);}finally{simulation.disabled=false;}}); summary.append(simulation,result);
      }
      if (payment?.state?.recoverable && payment.status === 'failed') { const retry=document.createElement('button'); retry.type='button'; retry.textContent=lang==='en'?'Try again or choose another method':'Réessayer ou choisir un autre moyen'; retry.addEventListener('click',()=>{retryPayment=true;completed=false;form.hidden=false;summary.textContent='';}); summary.append(retry); }
      form.hidden = true; offerAccount(payload.data.account_creation);
    } catch (reason) { error.textContent = reason instanceof Error ? reason.message : String(reason); }
  });
  load().catch((reason) => { error.textContent = reason instanceof Error ? reason.message : String(reason); });
})();
