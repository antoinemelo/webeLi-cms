(() => {
  'use strict';

  const channel = document.body.dataset.storefrontChannel;
  if (!channel) return;

  const lang = (document.documentElement.lang || 'fr').toLowerCase().startsWith('en') ? 'en' : 'fr';
  const labels = lang === 'en' ? {
    loading: 'Loading your cart…', empty: 'Your cart is empty.', quantity: 'Quantity', remove: 'Remove',
    subtotal: 'Subtotal', discount: 'Discount', shippingKnown: 'Estimated delivery', shippingUnknown: 'Delivery calculated at checkout',
    total: 'Total', added: 'Item added to cart.', updated: 'Cart updated.', removed: 'Item removed from cart.',
    conflict: 'This cart changed in another tab. The latest version is now displayed.', expired: 'This cart has expired or was revoked.',
    restart: 'Start a new cart', retry: 'Try again', inStock: 'In stock', backorder: 'On order', unavailable: 'Unavailable',
    reduced: 'Reduce quantity', choose: 'Choose another variant', priceChanged: 'Price updated', was: 'previously',
  } : {
    loading: 'Chargement du panier…', empty: 'Votre panier est vide.', quantity: 'Quantité', remove: 'Retirer',
    subtotal: 'Sous-total', discount: 'Remise', shippingKnown: 'Livraison estimée', shippingUnknown: 'Livraison calculée à l’étape suivante',
    total: 'Total', added: 'Article ajouté au panier.', updated: 'Panier mis à jour.', removed: 'Article retiré du panier.',
    conflict: 'Ce panier a changé dans un autre onglet. Sa version la plus récente est maintenant affichée.', expired: 'Ce panier a expiré ou a été révoqué.',
    restart: 'Créer un nouveau panier', retry: 'Réessayer', inStock: 'En stock', backorder: 'Sur commande', unavailable: 'Indisponible',
    reduced: 'Réduire la quantité', choose: 'Choisir une autre variante', priceChanged: 'Prix actualisé', was: 'auparavant',
  };
  const storageKey = `amcms.cart.${channel}`;
  const detectedPrefix = (() => {
    const script = [...document.scripts].find((item) => item.src.includes('/frontend/theme-default/'));
    return script ? new URL(script.src).pathname.split('/frontend/')[0] : '';
  })();
  const prefix = (document.body.dataset.appBasePath || detectedPrefix).replace(/\/$/, '');
  const apiBase = document.body.dataset.storefrontApiBase || `${prefix}/api/v1/sale/channels/${encodeURIComponent(channel)}`;
  const cartUrl = document.body.dataset.storefrontCartUrl || `${prefix}/cart`;
  const checkoutUrl = document.body.dataset.storefrontCheckoutUrl || `${prefix}/checkout`;
  const endpoint = (path) => `${apiBase.replace(/\/$/, '')}${path}`;
  const token = () => localStorage.getItem(storageKey) || '';
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const money = (minor, currency = 'CHF') => new Intl.NumberFormat(lang, { style: 'currency', currency }).format(Number(minor || 0) / 100);
  let cart = null;
  let opener = null;
  let loading = false;
  let pendingChanges = [];

  const setStatus = (message = '') => document.querySelectorAll('[data-cart-status]').forEach((node) => { node.textContent = message; });
  const setError = (message = '', recovery = false) => document.querySelectorAll('[data-cart-error]').forEach((node) => {
    node.classList.toggle('cart-error', Boolean(message));
    node.innerHTML = message ? `<p>${escapeHtml(message)}</p>${recovery ? `<button type="button" data-cart-retry>${escapeHtml(labels.retry)}</button>` : ''}` : '';
  });
  const setBusy = (busy) => {
    loading = busy;
    document.querySelectorAll('[data-cart-drawer], [data-cart-page]').forEach((node) => node.setAttribute('aria-busy', String(busy)));
    if (busy) setStatus(labels.loading);
  };

  const request = async (path, options = {}) => {
    const response = await fetch(endpoint(path), {
      ...options,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(options.headers || {}) },
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      const failure = new Error(body?.error?.message || body?.message || 'Le panier ne peut pas être mis à jour.');
      failure.status = response.status;
      failure.details = body?.error?.details || body?.details || {};
      throw failure;
    }
    return body.data;
  };

  const ensure = async () => {
    if (token()) return token();
    const data = await request(`/cart?lang=${encodeURIComponent(lang)}`, { method: 'POST', body: JSON.stringify({ data: {} }) });
    localStorage.setItem(storageKey, data.cart.token);
    cart = data.cart;
    return data.cart.token;
  };

  const availabilityLabel = (line) => line?.availability?.label || ({ backorder: labels.backorder, unavailable: labels.unavailable, available: labels.inStock }[line?.availability_state] || labels.inStock);
  const lineMarkup = (line) => {
    const promotion = Number(line.regular_unit_price_minor || 0) > Number(line.unit_price_minor || 0)
      ? `<span class="cart-line__regular">${money(line.regular_unit_price_minor, line.currency)}</span> <span>${money(line.unit_price_minor, line.currency)}</span>`
      : `<span>${money(line.unit_price_minor, line.currency)}</span>`;
    const oldPrice = line.price_changed_at && line.previous_unit_price_minor !== null
      ? `<small class="cart-line__change">${escapeHtml(labels.priceChanged)} — ${escapeHtml(labels.was)} ${money(line.previous_unit_price_minor, line.currency)}</small>` : '';
    const variant = [line.variant_name, line.sku].filter(Boolean).map(escapeHtml).join(' · ');
    const reservation = line.reservation?.backorder_quantity
      ? ` · ${Number(line.reservation.delivery_lead_time_days || 0)} ${lang === 'en' ? 'days' : 'jours'}` : '';
    const expiry = line.reservation?.expires_at
      ? ` · ${lang === 'en' ? 'reserved until' : 'réservé jusqu’à'} ${new Date(`${line.reservation.expires_at}Z`).toLocaleTimeString(lang, { hour: '2-digit', minute: '2-digit' })}` : '';
    const mediaValue = line.media_url || line.image_url || line.thumbnail_url;
    const media = mediaValue && String(mediaValue).startsWith('/') && prefix && mediaValue !== prefix && !String(mediaValue).startsWith(`${prefix}/`) ? `${prefix}${mediaValue}` : mediaValue;
    return `<article class="cart-line" data-line-id="${Number(line.id)}">
      ${media ? `<img class="cart-line__media" src="${escapeHtml(media)}" alt="${escapeHtml(line.media_alt || '')}">` : ''}
      <div class="cart-line__content"><strong>${escapeHtml(line.product_name)}</strong>${variant ? `<small>${variant}</small>` : ''}
        <div class="cart-line__price">${promotion}</div>${oldPrice}
        <small class="cart-line__availability" data-status="${escapeHtml(line.availability?.status || line.availability_state)}">${escapeHtml(availabilityLabel(line))}${reservation}${expiry}</small>
      </div>
      <div class="cart-line__actions"><label>${escapeHtml(labels.quantity)} <input data-cart-quantity type="number" inputmode="numeric" min="1" value="${Number(line.quantity)}"></label><button data-cart-remove type="button">${escapeHtml(labels.remove)}</button></div>
    </article>`;
  };

  const render = (value) => {
    const lines = value?.lines || [];
    const count = lines.reduce((sum, line) => sum + Number(line.quantity || 0), 0);
    document.querySelectorAll('[data-cart-count]').forEach((node) => { node.textContent = String(count); });
    document.querySelectorAll('[data-cart-lines]').forEach((root) => { root.innerHTML = lines.length ? lines.map(lineMarkup).join('') : `<p class="cart-empty">${escapeHtml(labels.empty)}</p>`; });
    document.querySelectorAll('[data-cart-summary]').forEach((root) => {
      root.innerHTML = value && lines.length ? `<dl class="cart-summary"><div><dt>${escapeHtml(labels.subtotal)}</dt><dd>${money(value.subtotal_minor, value.currency)}</dd></div>${Number(value.discount_total_minor || 0) > 0 ? `<div><dt>${escapeHtml(labels.discount)}</dt><dd>−${money(value.discount_total_minor, value.currency)}</dd></div>` : ''}<div><dt>${escapeHtml(Number(value.shipping_total_minor || 0) > 0 ? labels.shippingKnown : labels.shippingUnknown)}</dt><dd>${Number(value.shipping_total_minor || 0) > 0 ? money(value.shipping_total_minor, value.currency) : '—'}</dd></div></dl>` : '';
    });
    document.querySelectorAll('[data-cart-total]').forEach((node) => { node.textContent = value && lines.length ? `${labels.total} : ${money(value.grand_total_minor, value.currency)}` : ''; });
    document.querySelectorAll('[data-cart-checkout]').forEach((link) => {
      link.href = value && lines.length ? `${checkoutUrl}?channel=${encodeURIComponent(channel)}&cart_token=${encodeURIComponent(token())}&lang=${encodeURIComponent(lang)}` : '#';
      link.toggleAttribute('aria-disabled', !(value && lines.length) || pendingChanges.some((change) => change.requires_confirmation));
    });
    document.querySelectorAll('[data-cart-full]').forEach((link) => { link.href = `${cartUrl}?lang=${encodeURIComponent(lang)}`; });
  };

  const showChanges = (changes) => {
    pendingChanges = Array.isArray(changes) ? changes : [];
    if (!pendingChanges.length) return;
    const descriptions = pendingChanges.map((change) => {
      const before = change.before || {}; const after = change.after || {};
      const price = before.unit_price_minor !== after.unit_price_minor
        ? `${money(before.unit_price_minor, cart?.currency)} → ${money(after.unit_price_minor, cart?.currency)}` : '';
      const availability = before.availability_state !== after.availability_state
        ? `${before.availability_state} → ${after.availability_state}` : '';
      return `${change.product_name}: ${[price, availability].filter(Boolean).join(', ')}`;
    }).join(' ');
    const confirmation = pendingChanges.some((change) => change.requires_confirmation)
      ? `<button type="button" data-cart-change-confirm>${lang === 'en' ? 'Continue with these changes' : 'Continuer avec ces changements'}</button>` : '';
    document.querySelectorAll('[data-cart-error]').forEach((node) => {
      node.classList.add('cart-error');
      node.innerHTML = `<p>${escapeHtml(lang === 'en' ? 'Your cart was updated:' : 'Votre panier a été actualisé :')} ${escapeHtml(descriptions)}</p>${confirmation}`;
    });
    render(cart);
  };

  const expired = () => {
    cart = null;
    render(null);
    setError(labels.expired);
    document.querySelectorAll('[data-cart-lines]').forEach((root) => { root.innerHTML = `<button type="button" data-cart-restart>${escapeHtml(labels.restart)}</button>`; });
  };

  const load = async ({ preserveMessage = false } = {}) => {
    if (!token()) { cart = null; render(null); setStatus(''); return null; }
    setBusy(true);
    if (!preserveMessage) setError('');
    try {
      const data = await request(`/cart/${encodeURIComponent(token())}?lang=${encodeURIComponent(lang)}`);
      cart = data.cart;
      render(cart);
      showChanges(data.changes);
      return cart;
    } catch (reason) {
      if (reason.status === 404) expired();
      else setError(reason.message, true);
      return null;
    } finally {
      setBusy(false);
      if (!preserveMessage) setStatus('');
    }
  };

  const focusable = (drawer) => [...drawer.querySelectorAll('a[href]:not([aria-disabled="true"]),button:not([disabled]),input:not([disabled]),select:not([disabled]),[tabindex]:not([tabindex="-1"])')];
  const openDrawer = async () => {
    const drawer = document.querySelector('[data-cart-drawer]');
    if (!drawer) return;
    opener = document.activeElement;
    drawer.removeAttribute('hidden');
    document.querySelector('[data-cart-backdrop]')?.removeAttribute('hidden');
    document.querySelector('[data-cart-toggle]')?.setAttribute('aria-expanded', 'true');
    document.documentElement.classList.add('cart-is-open');
    drawer.querySelector('[data-cart-close]')?.focus();
    await load();
  };
  const closeDrawer = () => {
    document.querySelector('[data-cart-drawer]')?.setAttribute('hidden', '');
    document.querySelector('[data-cart-backdrop]')?.setAttribute('hidden', '');
    document.querySelector('[data-cart-toggle]')?.setAttribute('aria-expanded', 'false');
    document.documentElement.classList.remove('cart-is-open');
    if (opener instanceof HTMLElement && document.contains(opener)) opener.focus();
    else document.querySelector('[data-cart-toggle]')?.focus();
  };

  const mutate = async (path, options, successMessage) => {
    const before = cart ? structuredClone(cart) : null;
    setBusy(true); setError('');
    try {
      const data = await request(path, options);
      cart = data.cart;
      render(cart);
      setStatus(successMessage);
      return data;
    } catch (reason) {
      if (reason.status === 409) {
        await load({ preserveMessage: true });
        const changedLine = before?.lines?.find((oldLine) => {
          const current = cart?.lines?.find((line) => Number(line.id) === Number(oldLine.id));
          return !current || current.quantity !== oldLine.quantity || current.unit_price_minor !== oldLine.unit_price_minor;
        });
        setError(`${labels.conflict}${changedLine ? ` ${changedLine.product_name}.` : ''}`);
        setStatus(labels.conflict);
      } else if (reason.status === 404) expired();
      else setError(reason.message, true);
      return null;
    } finally { setBusy(false); }
  };

  document.addEventListener('click', async (event) => {
    const add = event.target.closest('[data-storefront-add-to-cart]');
    const toggle = event.target.closest('[data-cart-toggle]');
    const close = event.target.closest('[data-cart-close], [data-cart-backdrop]');
    const remove = event.target.closest('[data-cart-remove]');
    const retry = event.target.closest('[data-cart-retry]');
    const restart = event.target.closest('[data-cart-restart]');
    const confirmChanges = event.target.closest('[data-cart-change-confirm]');
    if (toggle) { await openDrawer(); return; }
    if (close) { closeDrawer(); return; }
    if (retry) { await load(); return; }
    if (restart) { localStorage.removeItem(storageKey); cart = null; setError(''); render(null); setStatus(labels.empty); return; }
    if (confirmChanges) { pendingChanges = []; setError(''); render(cart); setStatus(labels.updated); return; }
    if (event.target.closest('[data-cart-checkout][aria-disabled="true"]')) { event.preventDefault(); return; }
    if (add) {
      event.preventDefault();
      try {
        await ensure();
        if (!cart) await load();
        await mutate(`/cart/${encodeURIComponent(token())}/lines?lang=${encodeURIComponent(lang)}`, {
          method: 'POST', headers: { 'Idempotency-Key': crypto.randomUUID() },
          body: JSON.stringify({ data: { sellable_id: Number(add.dataset.sellableId), quantity: Number(add.dataset.quantity || 1), expected_version: cart?.version } }),
        }, labels.added);
        if (cart) await openDrawer();
      } catch (reason) { setError(reason.message, true); }
      return;
    }
    if (remove && cart && !loading) {
      const line = remove.closest('[data-line-id]');
      await mutate(`/cart/${encodeURIComponent(token())}/lines/${encodeURIComponent(line.dataset.lineId)}?lang=${encodeURIComponent(lang)}`, {
        method: 'DELETE', body: JSON.stringify({ data: { expected_version: cart.version } }),
      }, labels.removed);
    }
  });

  document.addEventListener('change', async (event) => {
    if (!event.target.matches('[data-cart-quantity]') || !cart || loading) return;
    const quantity = Math.max(1, Number.parseInt(event.target.value, 10) || 1);
    event.target.value = String(quantity);
    const line = event.target.closest('[data-line-id]');
    await mutate(`/cart/${encodeURIComponent(token())}/lines/${encodeURIComponent(line.dataset.lineId)}?lang=${encodeURIComponent(lang)}`, {
      method: 'PATCH', body: JSON.stringify({ data: { quantity, expected_version: cart.version } }),
    }, labels.updated);
  });

  document.addEventListener('keydown', (event) => {
    const drawer = document.querySelector('[data-cart-drawer]:not([hidden])');
    if (!drawer) return;
    if (event.key === 'Escape') { event.preventDefault(); closeDrawer(); return; }
    if (event.key !== 'Tab') return;
    const controls = focusable(drawer);
    if (!controls.length) { event.preventDefault(); drawer.focus(); return; }
    const first = controls[0]; const last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });

  window.addEventListener('storage', (event) => { if (event.key === storageKey) load({ preserveMessage: true }).then(() => setStatus(labels.conflict)); });
  load();
})();
