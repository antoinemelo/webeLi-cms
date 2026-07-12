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
    render(payload.data.cart);
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
      shipping_method: { code: values.get('shipping_method') }, payment: { code: values.get('payment_method') },
      terms_accepted: values.get('terms_accepted') === 'on', marketing_consent: values.get('marketing_consent') === 'on',
    };
    try {
      const key = crypto.randomUUID();
      const payload = await readJson(await fetch(`${endpoint}/checkout?lang=${lang}`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Idempotency-Key': key }, body: JSON.stringify({ data }) }));
      localStorage.removeItem(`amcms-sale-cart:${channel}`); summary.textContent = `${lang === 'en' ? 'Order' : 'Commande'} ${payload.data.order.order_number}`; form.hidden = true; offerAccount(payload.data.account_creation);
    } catch (reason) { error.textContent = reason instanceof Error ? reason.message : String(reason); }
  });
  load().catch((reason) => { error.textContent = reason instanceof Error ? reason.message : String(reason); });
})();
