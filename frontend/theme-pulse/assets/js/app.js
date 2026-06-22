
(function () {
  const header = document.querySelector('[data-site-header]');
  if (!header) return;

  function setMenuOpen(menu, button, open) {
    menu.classList.toggle('show', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function dropdownControls(dropdown) {
    return {
      menu: dropdown.querySelector('.dropdown-menu'),
      toggles: dropdown.querySelectorAll('.dropdown-toggle, .nav-parent-link'),
    };
  }

  function closeDropdown(dropdown) {
    dropdown.classList.remove('show');
    const { menu, toggles } = dropdownControls(dropdown);
    menu?.classList.remove('show');
    toggles.forEach((toggle) => toggle.setAttribute('aria-expanded', 'false'));
  }

  function closeOtherDropdowns(current) {
    header.querySelectorAll('.dropdown.show').forEach((dropdown) => {
      if (dropdown !== current) closeDropdown(dropdown);
    });
  }

  function toggleDropdown(dropdown, open) {
    const { menu, toggles } = dropdownControls(dropdown);
    dropdown.classList.toggle('show', open);
    menu?.classList.toggle('show', open);
    toggles.forEach((toggle) => toggle.setAttribute('aria-expanded', open ? 'true' : 'false'));
  }

  header.querySelectorAll('[data-nav-toggle]').forEach((button) => {
    const targetId = button.getAttribute('data-nav-toggle');
    const menu = targetId ? document.getElementById(targetId) : null;
    if (!menu) return;
    button.addEventListener('click', () => setMenuOpen(menu, button, !menu.classList.contains('show')));
  });

  header.querySelectorAll('.nav-item--has-children').forEach((dropdown) => {
    const parentLink = dropdown.querySelector('.nav-parent-link');
    const submenuButton = dropdown.querySelector('[data-submenu-toggle]');
    const menu = dropdown.querySelector('.dropdown-menu');
    if (!parentLink || !submenuButton || !menu) return;

    parentLink.setAttribute('aria-haspopup', 'true');
    submenuButton.setAttribute('aria-haspopup', 'true');

    submenuButton.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const open = !dropdown.classList.contains('show');
      closeOtherDropdowns(dropdown);
      toggleDropdown(dropdown, open);
    });

    function isMobileNavigation() {
      return window.matchMedia('(max-width: 991.98px)').matches;
    }

    function toggleCurrentDropdown() {
      const open = !dropdown.classList.contains('show');
      closeOtherDropdowns(dropdown);
      toggleDropdown(dropdown, open);
    }

    parentLink.addEventListener('click', (event) => {
      if (!isMobileNavigation()) return;
      event.preventDefault();
      event.stopPropagation();
      toggleCurrentDropdown();
    });

    parentLink.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowDown' && event.key !== 'Enter' && event.key !== ' ') return;
      if ((event.key === 'Enter' || event.key === ' ') && !isMobileNavigation()) return;
      event.preventDefault();
      closeOtherDropdowns(dropdown);
      const open = event.key === 'ArrowDown' ? true : !dropdown.classList.contains('show');
      toggleDropdown(dropdown, open);
      if (event.key === 'ArrowDown') menu.querySelector('a, button')?.focus();
    });
  });

  header.querySelectorAll('.language-switch .dropdown-toggle').forEach((toggle) => {
    const parent = toggle.closest('.dropdown');
    const menu = parent ? parent.querySelector('.dropdown-menu') : null;
    if (!parent || !menu) return;
    toggle.setAttribute('aria-haspopup', 'true');
    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const open = !parent.classList.contains('show');
      closeOtherDropdowns(parent);
      toggleDropdown(parent, open);
    });
    toggle.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      const open = !parent.classList.contains('show');
      closeOtherDropdowns(parent);
      toggleDropdown(parent, open);
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    header.querySelectorAll('.dropdown.show').forEach(closeDropdown);
  });

  document.addEventListener('click', (event) => {
    const currentDropdown = event.target.closest?.('.dropdown');
    header.querySelectorAll('.dropdown.show').forEach((dropdown) => {
      if (dropdown !== currentDropdown) closeDropdown(dropdown);
    });
  });
})();


(function () {
  if (document.documentElement.dataset.amcmsPreview !== '1') return;
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.getAttribute('role') !== 'search') return;
    event.preventDefault();
  }, true);
})();

document.addEventListener('click', (event) => {
  const scrollLink = event.target.closest('[data-scroll-to]');
  if (!scrollLink) return;
  event.preventDefault();
  document.querySelector(scrollLink.dataset.scrollTo)?.scrollIntoView({ behavior: 'smooth' });
});

(function () {
  const blocks = document.querySelectorAll('[data-form-block]');
  if (!blocks.length) return;

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
  }

  function basePath() {
    const scripts = [document.currentScript, ...Array.from(document.scripts)].filter((item) => item && item.src);
    const markers = [
      '/frontend/theme-default/assets/js/app.js',
      '/frontend/theme-aurora/assets/js/app.js',
      '/frontend/theme-pulse/assets/js/app.js',
      '/assets/theme-default/js/app.js',
      '/assets/theme-aurora/js/app.js',
      '/assets/theme-pulse/js/app.js',
      '/assets/js/app.js',
    ];
    for (const script of scripts) {
      try {
        const path = new URL(script.src, window.location.origin).pathname;
        for (const marker of markers) {
          const index = path.indexOf(marker);
          if (index > 0) return path.slice(0, index);
        }
      } catch {}
    }
    return '';
  }

  const publicBasePath = basePath();

  function withLang(url) {
    const lang = document.documentElement.lang || 'fr';
    const separator = url.includes('?') ? '&' : '?';
    return `${url}${separator}lang=${encodeURIComponent(lang)}`;
  }

  function apiPath(block, key, submit) {
    const direct = submit ? block.dataset.formSubmitUrl : block.dataset.formApiUrl;
    if (direct) return withLang(direct);
    return withLang(`${publicBasePath}/api/v1/forms/${encodeURIComponent(key)}${submit ? '/submit' : ''}`);
  }

  function inputHtml(field) {
    const key = esc(field.field_key);
    const label = `<label for="form_${key}">${esc(field.label)}${field.is_required ? ' *' : ''}</label>`;
    const help = field.help_text ? `<small id="form_${key}_help">${esc(field.help_text)}</small>` : '';
    const common = `id="form_${key}" name="${key}" ${field.is_required ? 'required' : ''} ${field.placeholder ? `placeholder="${esc(field.placeholder)}"` : ''}`;
    if (field.field_type === 'textarea') return `<div class="form-field form-field--${esc(field.width)}">${label}<textarea ${common} rows="5">${esc(field.default_value || '')}</textarea>${help}<div class="form-error" data-error-for="${key}"></div></div>`;
    if (['select', 'radio', 'checkboxes'].includes(field.field_type)) {
      const options = Array.isArray(field.options) ? field.options : [];
      if (field.field_type === 'select') return `<div class="form-field form-field--${esc(field.width)}">${label}<select ${common}>${options.map(o => `<option value="${esc(o.value ?? o.label)}">${esc(o.label ?? o.value)}</option>`).join('')}</select>${help}<div class="form-error" data-error-for="${key}"></div></div>`;
      return `<fieldset class="form-field form-field--${esc(field.width)}"><legend>${esc(field.label)}${field.is_required ? ' *' : ''}</legend>${options.map(o => `<label class="form-check"><input type="${field.field_type === 'radio' ? 'radio' : 'checkbox'}" name="${key}${field.field_type === 'checkboxes' ? '[]' : ''}" value="${esc(o.value ?? o.label)}"> ${esc(o.label ?? o.value)}</label>`).join('')}${help}<div class="form-error" data-error-for="${key}"></div></fieldset>`;
    }
    if (field.field_type === 'consent') return `<div class="form-field form-field--full"><label class="form-check"><input type="checkbox" id="form_${key}" name="${key}" value="1" ${field.is_required ? 'required' : ''}> ${esc(field.label)}</label>${help}<div class="form-error" data-error-for="${key}"></div></div>`;
    const type = ['email','tel','url','number','date','hidden','checkbox'].includes(field.field_type) ? field.field_type : 'text';
    if (type === 'hidden') return `<input type="hidden" name="${key}" value="${esc(field.default_value || '')}">`;
    return `<div class="form-field form-field--${esc(field.width)}">${label}<input type="${type}" ${common} value="${esc(field.default_value || '')}">${help}<div class="form-error" data-error-for="${key}"></div></div>`;
  }

  async function mount(block) {
    const key = block.dataset.formBlock;
    const mount = block.querySelector('.form-block__mount');
    if (!key || !mount) return;
    try {
      const response = await fetch(apiPath(block, key, false), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const payload = await response.json();
      if (!response.ok || !payload?.data?.form) throw new Error('FORM_UNAVAILABLE');
      const form = payload.data.form;
      mount.innerHTML = `<form class="native-form" novalidate><input type="hidden" name="_started_at" value="${esc(block.dataset.formStarted || Math.floor(Date.now()/1000))}"><input class="form-hp" type="text" name="${esc(form.honeypot_field || 'website')}" tabindex="-1" autocomplete="off" aria-hidden="true"><div class="form-grid">${form.fields.map(inputHtml).join('')}</div><p class="form-message" data-form-message></p><button class="btn btn--primary" type="submit">${esc(form.submit_label || 'Envoyer')}</button></form>`;
      const formEl = mount.querySelector('form');
      formEl.addEventListener('submit', async (event) => {
        event.preventDefault();
        formEl.querySelectorAll('[data-error-for]').forEach(e => { e.textContent = ''; });
        const values = {};
        new FormData(formEl).forEach((value, name) => {
          if (name.endsWith('[]')) {
            const k = name.slice(0, -2); values[k] = values[k] || []; values[k].push(String(value));
          } else { values[name] = String(value); }
        });
        const message = formEl.querySelector('[data-form-message]');
        const submit = formEl.querySelector('button[type="submit"]');
        submit.disabled = true;
        try {
          const res = await fetch(apiPath(block, key, true), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ data: values }) });
          const out = await res.json();
          if (!res.ok || out.error) {
            const fields = out.error?.fields || {};
            Object.entries(fields).forEach(([field, messages]) => { const target = formEl.querySelector(`[data-error-for="${CSS.escape(field)}"]`); if (target) target.textContent = messages.join(' '); });
            message.textContent = out.error?.message || 'Le formulaire contient des erreurs.';
            return;
          }
          formEl.reset(); message.textContent = out.data.message || form.success_message || 'Merci, votre message a été envoyé.';
        } finally { submit.disabled = false; }
      });
    } catch (error) {
      mount.textContent = 'Le formulaire est momentanément indisponible.';
    }
  }

  blocks.forEach(mount);
})();

(function () {
  function fallbackCopy(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.top = '-1000px';
    textarea.style.left = '-1000px';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    try {
      return document.execCommand('copy');
    } finally {
      textarea.remove();
    }
  }

  async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return true;
    }
    return fallbackCopy(text);
  }

  document.addEventListener('click', async (event) => {
    const button = event.target.closest?.('[data-copy-url]');
    if (!button) return;
    event.preventDefault();
    const value = button.getAttribute('data-copy-url') || window.location.href;
    const toolbar = button.closest('.social-share');
    const status = toolbar?.querySelector('.social-share__status');
    const previousTitle = button.getAttribute('title') || 'Copier l’URL';
    try {
      await copyText(value);
      button.classList.add('is-copied');
      button.setAttribute('title', 'URL copiée');
      if (status) status.textContent = 'URL copiée dans le presse-papier.';
      window.setTimeout(() => {
        button.classList.remove('is-copied');
        button.setAttribute('title', previousTitle);
        if (status) status.textContent = '';
      }, 1800);
    } catch {
      if (status) status.textContent = 'Copie impossible automatiquement.';
    }
  });
})();

