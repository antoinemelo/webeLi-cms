(() => {
  'use strict';

  const root = document.querySelector('[data-cookie-consent-root]');
  if (!root) return;

  function basePath() {
    const explicit = document.documentElement.dataset.basePath || '';
    if (explicit) return explicit.replace(/\/$/, '');
    const script = document.currentScript || Array.from(document.scripts).find((item) => item.src && item.src.includes('/frontend/theme-default/assets/js/cookie-consent.js'));
    if (!script || !script.src) return '';
    try {
      const path = new URL(script.src, window.location.origin).pathname;
      const marker = '/frontend/theme-default/assets/js/cookie-consent.js';
      const index = path.indexOf(marker);
      return index > 0 ? path.slice(0, index) : '';
    } catch (_) {
      return '';
    }
  }

  const apiBase = basePath();
  const language = (root.dataset.language || document.documentElement.lang || 'fr').toLowerCase();
  const state = { config: null, choices: {}, uid: '', open: false };

  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char]));
  const keyOf = (name) => `amcms_cookie_${name}`.replace(/[^a-zA-Z0-9_]/g, '_');
  const nowIso = () => new Date().toISOString();
  const uid = () => (crypto?.randomUUID ? crypto.randomUUID() : `cc_${Date.now()}_${Math.random().toString(16).slice(2)}`);

  function readCookie(name) {
    const item = document.cookie.split('; ').find((row) => row.startsWith(`${encodeURIComponent(name)}=`));
    if (!item) return null;
    try { return JSON.parse(decodeURIComponent(item.split('=').slice(1).join('='))); } catch { return null; }
  }

  function writeCookie(name, value, days) {
    const expires = new Date(Date.now() + days * 86400000).toUTCString();
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(JSON.stringify(value))}; Expires=${expires}; Path=/; SameSite=Lax${secure}`;
  }

  function eraseCookie(name) {
    document.cookie = `${encodeURIComponent(name)}=; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Path=/; SameSite=Lax`;
  }

  function hasOptionalChoices(config) {
    if (typeof config?.has_optional_choices === 'boolean') return config.has_optional_choices;
    return (config?.categories || []).some((category) => Number(category.is_required) !== 1);
  }

  function categoryDefaults(config, accepted) {
    const choices = {};
    (config.categories || []).forEach((category) => { choices[category.category_key] = Number(category.is_required) === 1 || Boolean(accepted); });
    return choices;
  }

  function normalizeStored(stored, config) {
    if (!stored || stored.version !== config.setting.consent_version || !stored.choices) return null;
    const choices = categoryDefaults(config, false);
    Object.keys(choices).forEach((key) => { choices[key] = choices[key] || Boolean(stored.choices[key]); });
    return { ...stored, choices };
  }

  function consentCookieName(config) { return config?.setting?.cookie_name || 'amcms_cookie_consent'; }

  async function fetchConfig() {
    const response = await fetch(`${apiBase}/api/v1/cookies/config?lang=${encodeURIComponent(language)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    const payload = await response.json();
    return payload.data;
  }

  async function logConsent(action) {
    if (!state.config?.enabled || !state.uid) return;
    try {
      await fetch(`${apiBase}/api/v1/cookies/consent`, {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ data: { consent_uid: state.uid, action, choices: state.choices } })
      });
    } catch (_) { /* Logging must never break the public page. */ }
  }

  function closeConsentWindow() {
    const banner = root.querySelector('.cookie-consent');
    if (banner) banner.hidden = true;
    state.open = false;
  }

  function save(action) {
    const config = state.config;
    state.uid ||= uid();
    const stored = { uid: state.uid, version: config.setting.consent_version, choices: state.choices, saved_at: nowIso() };
    writeCookie(consentCookieName(config), stored, Number(config.setting.consent_lifetime_days || 180));
    logConsent(action);
    activateAllowedScripts();
    render(false);
    closeConsentWindow();
  }

  function acceptAll() { state.choices = categoryDefaults(state.config, true); save('accept_all'); }
  function rejectAll() { state.choices = categoryDefaults(state.config, false); save('reject_all'); }
  function saveChoices() {
    document.querySelectorAll('[data-cookie-choice]').forEach((input) => { state.choices[input.value] = input.checked || input.disabled; });
    save('save_choices');
  }
  function revoke() { eraseCookie(consentCookieName(state.config)); state.choices = categoryDefaults(state.config, false); state.uid = uid(); logConsent('revoke'); render(true); }

  function button(label, action, extra = '') { return `<button type="button" class="cookie-consent__button ${extra}" data-cookie-action="${action}">${esc(label)}</button>`; }

  function render(open) {
    const config = state.config;
    if (!config?.enabled) { root.hidden = true; return; }
    state.open = open;
    const t = config.texts || {};
    const categories = config.categories || [];
    const canChoose = hasOptionalChoices(config);
    const quickActions = canChoose
      ? `${button(t.reject_all_label || 'Tout refuser', 'reject', Number(config.setting.reject_equal_prominence) === 1 ? 'cookie-consent__button--primary' : 'cookie-consent__button--secondary')}${button(t.accept_all_label || 'Tout accepter', 'accept', 'cookie-consent__button--primary')}`
      : '';
    root.hidden = false;
    root.innerHTML = `
      <section class="cookie-consent cookie-consent--${esc(config.setting.banner_position || 'bottom')}" role="dialog" aria-modal="false" aria-labelledby="cookie-consent-title">
        <div class="cookie-consent__summary">
          <div>
            <h2 id="cookie-consent-title">${esc(t.banner_title || 'Gestion des cookies')}</h2>
            <p>${esc(t.banner_summary || '')}</p>
          </div>
          ${quickActions ? `<div class="cookie-consent__actions">${quickActions}</div>` : ''}
        </div>
        <div class="cookie-consent__panel" ${open ? '' : 'hidden'}>
          <div class="cookie-consent__panel-head">
            <h3>${esc(t.preferences_title || 'Préférences de confidentialité')}</h3>
            <p>${esc(t.preferences_summary || '')}</p>
          </div>
          <div class="cookie-consent__categories">
            ${categories.map((category) => `
              <label class="cookie-consent__category">
                <span><strong>${esc(category.name || category.category_key)}</strong><small>${esc(category.description || '')}</small></span>
                <input type="checkbox" value="${esc(category.category_key)}" data-cookie-choice ${Number(category.is_required) === 1 ? 'checked disabled' : state.choices[category.category_key] ? 'checked' : ''}>
              </label>
            `).join('')}
          </div>
          ${t.legal_notice_html ? `<div class="cookie-consent__legal">${t.legal_notice_html}</div>` : ''}
          <div class="cookie-consent__actions cookie-consent__actions--panel">
            ${button(t.save_choices_label || 'Enregistrer mes choix', 'save', 'cookie-consent__button--primary')}
          </div>
        </div>
      </section>
      ${Number(config.setting.show_floating_button) === 1 ? `<button type="button" class="cookie-consent-manage" data-cookie-action="manage">${esc(t.manage_link_label || 'Gérer mes cookies')}</button>` : ''}
    `;
  }

  function activatePlainScripts() {
    document.querySelectorAll('script[type="text/plain"][data-am-cookie-category], script[type="text/plain"][data-cookie-category]').forEach((placeholder) => {
      const category = placeholder.dataset.amCookieCategory || placeholder.dataset.cookieCategory;
      if (!state.choices[category] || placeholder.dataset.cookieLoaded === '1') return;
      const script = document.createElement('script');
      [...placeholder.attributes].forEach((attr) => {
        if (!['type', 'data-am-cookie-category', 'data-cookie-category', 'data-cookie-loaded'].includes(attr.name)) script.setAttribute(attr.name, attr.value);
      });
      script.text = placeholder.text || '';
      placeholder.dataset.cookieLoaded = '1';
      placeholder.replaceWith(script);
    });
  }

  function activateBoundScripts() {
    const loaded = new Set(Array.from(document.querySelectorAll('[data-cookie-binding-loaded]')).map((node) => node.getAttribute('data-cookie-binding-loaded')));
    (state.config.script_bindings || []).forEach((binding) => {
      if (binding.trigger_mode !== 'after_consent' || !state.choices[binding.category_key] || loaded.has(binding.binding_key)) return;
      if (binding.script_kind === 'external' && binding.src_url) {
        const script = document.createElement('script');
        script.src = binding.src_url;
        script.async = true;
        Object.entries(binding.attributes || {}).forEach(([key, value]) => script.setAttribute(key, String(value)));
        script.setAttribute('data-cookie-binding-loaded', binding.binding_key);
        document.body.appendChild(script);
      } else if (binding.script_kind === 'inline' && binding.inline_code) {
        const script = document.createElement('script');
        script.text = binding.inline_code;
        script.setAttribute('data-cookie-binding-loaded', binding.binding_key);
        document.body.appendChild(script);
      } else if (binding.script_kind === 'html' && binding.inline_code) {
        const div = document.createElement('div');
        div.hidden = true;
        div.setAttribute('data-cookie-binding-loaded', binding.binding_key);
        div.innerHTML = binding.inline_code;
        document.body.appendChild(div);
      }
    });
  }

  function activateAllowedScripts() { activatePlainScripts(); activateBoundScripts(); document.dispatchEvent(new CustomEvent('amcms:cookie-consent-updated', { detail: { choices: state.choices } })); }

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-cookie-action], [data-cookie-consent-manage]');
    if (!trigger) return;
    const action = trigger.dataset.cookieAction || 'manage';
    if (action === 'accept') acceptAll();
    if (action === 'reject') rejectAll();
    if (action === 'manage') render(true);
    if (action === 'save') saveChoices();
    if (action === 'revoke') revoke();
  });

  fetchConfig().then((config) => {
    state.config = config;
    if (!config?.enabled) return;
    const stored = normalizeStored(readCookie(consentCookieName(config)), config);
    if (stored) {
      state.uid = stored.uid || uid();
      state.choices = stored.choices;
      activateAllowedScripts();
      render(false);
      closeConsentWindow();
    } else {
      state.uid = uid();
      state.choices = categoryDefaults(config, false);
      render(true);
    }
  }).catch(() => { root.hidden = true; });
})();
