(() => {
  const root = document.querySelector('[data-customer-account]'); if (!root) return;
  const api = `${root.dataset.basePath || ''}/api/v1/customer`;
  const login = root.querySelector('[data-login]'); const dashboard = root.querySelector('[data-dashboard]');
  const error = root.querySelector('[data-error]'); const logout = root.querySelector('[data-logout]');
  const request = async (path, options = {}) => { const response = await fetch(`${api}${path}`, { credentials: 'include', headers: { Accept: 'application/json', ...(options.body ? {'Content-Type':'application/json'} : {}), ...(options.headers || {}) }, ...options }); const payload = await response.json(); if (!response.ok) throw new Error(payload?.error?.message || 'Action impossible'); return payload.data; };
  const row = (text, action) => { const item = document.createElement('div'); item.className = 'account-row'; const label = document.createElement('span'); label.textContent = text; item.append(label); if (action) item.append(action); return item; };
  const load = async () => {
    await request('/me'); login.hidden = true; dashboard.hidden = false; logout.hidden = false;
    const [orders, addresses] = await Promise.all([request('/orders'), request('/addresses')]);
    const orderList = root.querySelector('[data-orders]'); orderList.textContent = '';
    for (const order of orders.orders || []) { const button = document.createElement('button'); button.type = 'button'; button.textContent = 'Voir'; button.addEventListener('click', () => showOrder(order.id)); orderList.append(row(`${order.order_number} — ${(order.grand_total_minor / 100).toFixed(2)} ${order.currency}`, button)); }
    const addressList = root.querySelector('[data-addresses]'); addressList.textContent = '';
    for (const item of addresses.addresses || []) addressList.append(row(`${item.label} — ${item.address.line1}, ${item.address.city}`));
  };
  const showOrder = async (id) => { const data = await request(`/orders/${id}`); const detail = root.querySelector('[data-order-detail]'); detail.textContent = ''; detail.append(row(`${data.order.order_number} — ${data.order.status}`)); for (const line of data.order.lines || []) detail.append(row(`${line.quantity} × ${line.product_name}`)); if (data.order.lines?.length) { const button = document.createElement('button'); button.type = 'button'; button.textContent = 'Demander le retour du premier article'; button.addEventListener('click', async () => { const reason = window.prompt('Motif du retour') || 'Demande client'; await request(`/orders/${id}/returns`, {method:'POST',headers:{'Idempotency-Key':crypto.randomUUID()},body:JSON.stringify({data:{reason,lines:[{order_line_id:data.order.lines[0].id,quantity:1}]}})}); button.disabled = true; button.textContent = 'Retour demandé'; }); detail.append(button); } };
  login.addEventListener('submit', async (event) => { event.preventDefault(); error.textContent = ''; const values = Object.fromEntries(new FormData(login)); try { await request('/login', {method:'POST', body:JSON.stringify({data:values})}); await load(); } catch (reason) { error.textContent = reason.message; } });
  logout.addEventListener('click', async () => { await request('/logout', {method:'DELETE'}); location.reload(); });
  root.querySelectorAll('[data-tab]').forEach((button) => button.addEventListener('click', () => root.querySelectorAll('[data-panel]').forEach((panel) => { panel.hidden = panel.dataset.panel !== button.dataset.tab; })));
  root.querySelector('[data-address-form]').addEventListener('submit', async (event) => { event.preventDefault(); const values = Object.fromEntries(new FormData(event.currentTarget)); await request('/addresses', {method:'POST',body:JSON.stringify({data:{label:values.label,type:'both',is_default:true,address:values}})}); await load(); });
  root.querySelector('[data-profile-form]').addEventListener('submit', async (event) => { event.preventDefault(); await request('/me', {method:'PATCH',body:JSON.stringify({data:Object.fromEntries(new FormData(event.currentTarget))})}); });
  load().catch(() => { login.hidden = false; dashboard.hidden = true; });
})();
