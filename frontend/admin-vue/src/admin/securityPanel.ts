// @ts-nocheck
// Integrated legacy security panel runtime. Kept inside the Vite admin bundle; no standalone admin-security-panel.js asset is required.
(function(){
  const ADMIN = window.__AMCMS_ADMIN__ || {};
  const apiBase = (ADMIN.apiBasePath || '/admin/api').replace(/\/$/, '');
  let csrfToken = '';
  let adminContext = null;
  let securityState = null;
  let securityStateSiteId = 0;
  let usersCache = [];
  let activeSiteSecurityTab = null;
  const SECURITY_BLUEPRINT_HELP = {
    tokens: 'API headless publique : tokens Bearer, documentation, OpenAPI et exemples front-end pour le site sélectionné.',
    webhooks: 'Configuration des webhooks de publication signés par site.'
  };
  const HEADLESS_PUBLIC_SCOPES = ['headless:read', 'content:read', 'media:read', 'search:read', 'menus:read', 'taxonomies:read'];

  const h = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const dataBody = (data) => JSON.stringify({data});
  const lines = (v) => String(v || '').split(/[\n,;\s]+/).map(x => x.trim()).filter(Boolean);
  const perms = () => new Set([...(adminContext?.permissions || []), ...Object.keys(adminContext?.capabilities || {}).filter(k => adminContext.capabilities[k])]);
  const hasPermission = (key) => perms().has('*') || perms().has(key);
  const canReadTokens = () => hasPermission('security.tokens.read') || hasPermission('security.tokens.manage');
  const canManageTokens = () => hasPermission('security.tokens.manage');
  const canReadWebhooks = () => hasPermission('security.webhooks.read') || hasPermission('security.webhooks.manage');
  const canManageWebhooks = () => hasPermission('security.webhooks.manage');
  const canReadCors = () => hasPermission('security.cors.read') || hasPermission('security.cors.manage');
  const canManageCors = () => hasPermission('security.cors.manage');
  const canManageEmail2fa = () => hasPermission('users.email_2fa.manage');

  async function api(path, options={}){
    const headers = Object.assign({'X-Contract-Version':'admin-api-v1','Accept':'application/json'}, options.headers || {});
    if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
    if (csrfToken && /^(POST|PATCH|PUT|DELETE)$/i.test(options.method || 'GET')) headers['X-CSRF-Token'] = csrfToken;
    const res = await fetch(apiBase + path, Object.assign({credentials:'same-origin'}, options, {headers}));
    const json = await res.json().catch(()=>({}));
    if (!res.ok) throw new Error(json?.error?.message || `HTTP ${res.status}`);
    return json.data || json;
  }

  function currentRoute(){ return location.pathname + location.search + location.hash; }
  function isSettingsRoute(){ return /\/app\/settings(?:$|[/?#])/.test(currentRoute()) || /\/settings(?:$|[/?#])/.test(currentRoute()); }
  function isIamUsersRoute(){ return /iam\/users/.test(currentRoute()) || /Modifier l[’']utilisateur/i.test(document.body.innerText || '') || !!document.querySelector('details.iam-advanced-config, [data-iam-user-editor], form[data-iam-user-form]'); }

  function querySiteId(){
    const params = new URLSearchParams(location.search || '');
    const explicit = Number(params.get('site_id') || params.get('siteId') || 0);
    return explicit > 0 ? explicit : 0;
  }

  function selectedSiteId(){
    const select = document.querySelector('select[name="site_id"], select[aria-label="Site"], select[data-site-selector], [data-current-site-id] select');
    const selected = Number(select?.value || 0);
    if (selected > 0) return selected;
    const dataNode = document.querySelector('[data-current-site-id]');
    const dataId = Number(dataNode?.dataset.currentSiteId || 0);
    if (dataId > 0) return dataId;
    const urlId = querySiteId();
    if (urlId > 0) return urlId;
    const ctxId = Number(adminContext?.site?.id || adminContext?.current_site_id || ADMIN.siteId || ADMIN.currentSiteId || 0);
    if (ctxId > 0) return ctxId;
    return 1;
  }

  function siteName(siteId){
    const site = securityState?.sites?.find(s => Number(s.id) === Number(siteId)) || adminContext?.available_sites?.find?.(s => Number(s.id) === Number(siteId));
    return site?.name || site?.site_key || `Site #${siteId}`;
  }


  function securityBlueprint(state, key){
    const map = state?.blueprints || securityState?.blueprints || {};
    return map[key] || {};
  }
  function blueprintField(state, key, handle){
    return securityBlueprint(state, key)?.fields?.[handle] || {};
  }
  function blueprintLabel(state, key, fallback){
    return securityBlueprint(state, key)?.label || fallback;
  }
  function blueprintDescription(state, key, fallback){
    return securityBlueprint(state, key)?.description || fallback || '';
  }
  function fieldLabel(state, key, handle, fallback){
    return blueprintField(state, key, handle)?.label || fallback || handle;
  }
  function fieldHelp(state, key, handle){
    return blueprintField(state, key, handle)?.help_text || '';
  }
  function fieldDefault(state, key, handle, fallback=''){
    const v = blueprintField(state, key, handle)?.default;
    if (Array.isArray(v)) return v.join(' ');
    if (v === null || v === undefined) return fallback;
    return String(v);
  }
  function helpHint(text){
    const value = h(text || '');
    return value ? `<span class="info-hint"><button type="button" class="info-hint__button" aria-label="Aide" title="Aide"><span class="info-hint__icon">i</span></button><span class="info-hint__popover" role="tooltip">${value}</span></span>` : '';
  }
  function labelWithHint(label, help){
    return `<span class="native-label-with-hint"><span>${h(label)}</span>${helpHint(help)}</span>`;
  }
  function describedInput(state, blueprintKey, handle, inputHtml){
    const label = fieldLabel(state, blueprintKey, handle, handle);
    const help = fieldHelp(state, blueprintKey, handle);
    return `<label class="amcms-security-field">${labelWithHint(label, help)}${inputHtml}</label>`;
  }
  function datetimeLocalValue(value){
    const raw = String(value || '').trim();
    if (!raw) return '';
    return raw.replace(' ', 'T').slice(0, 16);
  }
  function datetimeLocalToUtc(value){
    const raw = String(value || '').trim();
    return raw ? raw.replace('T', ' ') + (raw.length === 16 ? ':00' : '') : '';
  }

  function basePath(){
    return String(ADMIN.siteBasePath || ADMIN.basePath || '').replace(/\/+$/, '');
  }
  function appBasePath(){
    const explicitApp = String(ADMIN.appBasePath || ADMIN.cmsBasePath || '').replace(/\/+$/, '');
    if (explicitApp) return explicitApp;
    const fromApi = String(ADMIN.apiBasePath || apiBase || '').replace(/\/+$/, '').replace(/(?:\/[^/]+)?\/admin\/api$/, '');
    if (fromApi) return fromApi;
    const explicitBase = String(ADMIN.basePath || '').replace(/\/+$/, '');
    if (explicitBase) return explicitBase;
    const match = location.pathname.match(/^(.*?)(?:\/[^/]+)?\/admin(?:\/|$)/);
    return match ? match[1].replace(/\/+$/, '') : '';
  }
  function normalizeSlashPath(path){
    const value = String(path || '').trim();
    if (!value || value === '/') return '';
    return '/' + value.replace(/^\/+|\/+$/g, '');
  }
  function joinSlashPaths(...parts){
    return normalizeSlashPath(parts.map(part => String(part || '').trim()).filter(Boolean).join('/'));
  }
  function pathIncludesBase(path, base){
    const cleanPath = normalizeSlashPath(path);
    const cleanBase = normalizeSlashPath(base);
    return !!cleanBase && (cleanPath === cleanBase || cleanPath.startsWith(`${cleanBase}/`));
  }
  function sitePathWithAppBase(sitePath){
    const appBase = normalizeSlashPath(appBasePath());
    const cleanSitePath = normalizeSlashPath(sitePath);
    if (!cleanSitePath) return appBase;
    if (!appBase || pathIncludesBase(cleanSitePath, appBase)) return cleanSitePath;
    return joinSlashPaths(appBase, cleanSitePath);
  }
  function absolutePublicPath(path, siteId){
    const base = selectedSitePublicBase(siteId);
    const clean = '/' + String(path || '').replace(/^\/+/, '');
    return `${base}${clean}`;
  }
  function selectedSite(siteId){
    return adminContext?.available_sites?.find?.(s => Number(s.id) === Number(siteId))
      || (Number(adminContext?.site?.id || 0) === Number(siteId) ? adminContext.site : null)
      || securityState?.sites?.find(s => Number(s.id) === Number(siteId))
      || null;
  }
  function selectedSitePublicBase(siteId){
    const site = selectedSite(siteId);
    const explicitPath = sitePathWithAppBase(site?.public_path || site?.request_base_path || site?.base_path || ADMIN.siteBasePath || '');
    const explicitUrl = String(site?.public_url || '').replace(/\/+$/, '');
    if (explicitUrl) {
      try {
        const parsed = new URL(explicitUrl, location.origin);
        if (parsed.origin === location.origin) {
          const pathBase = sitePathWithAppBase(parsed.pathname || explicitPath);
          parsed.pathname = pathBase || '/';
          parsed.search = '';
          parsed.hash = '';
          return parsed.toString().replace(/\/+$/, '');
        }
      } catch {
        // Keep path-based construction below when public_url is malformed.
      }
      return explicitUrl;
    }
    const pathBase = explicitPath || normalizeSlashPath(appBasePath());
    return `${location.origin}${pathBase}`.replace(/\/+$/, '');
  }
  function publicApiBaseUrl(siteId){ return absolutePublicPath('/api/v1', siteId); }
  // Public API endpoints are site-aware: a selected sub-site such as /site_a
  // must generate the same site-aware base path before /api/v1 and /api/v1/openapi.*.
  function publicDocsUrl(file='index.html', siteId){ return absolutePublicPath(`/docs/public-api/${file}`, siteId); }
  function openApiUrl(siteId){ return absolutePublicPath('/api/v1/openapi.json', siteId); }
  function openApiYamlUrl(siteId){ return absolutePublicPath('/api/v1/openapi.yaml', siteId); }
  function selectedSiteKey(siteId){
    const site = selectedSite(siteId);
    return String(site?.site_key || site?.key || site?.slug || 'main');
  }
  function selectedLang(){ return String(adminContext?.current_content_language_code || adminContext?.current_language_code || adminContext?.site?.default_language_code || adminContext?.language || adminContext?.lang || adminContext?.locale || 'fr').slice(0, 2) || 'fr'; }
  function headlessScopes(state){
    const raw = Array.isArray(state?.available_scopes) ? state.available_scopes : [];
    const normalized = new Set([...HEADLESS_PUBLIC_SCOPES, ...raw.filter(scope => /^(headless|content|media|search|menus|taxonomies):read$/.test(String(scope)))]);
    return [...normalized];
  }
  function codeBlock(title, code){
    return `<div class="amcms-headless-code-card"><h4>${h(title)}</h4><pre class="amcms-headless-code"><code>${h(code)}</code></pre><div class="amcms-security-actions"><button type="button" class="amcms-security-btn" data-copy-snippet="${h(code)}">Copier</button></div></div>`;
  }
  function headlessDocLinksHtml(siteId){
    return `<div class="amcms-headless-links"><a class="amcms-security-btn" href="${h(openApiUrl(siteId))}" target="_blank" rel="noopener">OpenAPI v1 JSON</a><a class="amcms-security-btn" href="${h(openApiYamlUrl(siteId))}" target="_blank" rel="noopener">OpenAPI v1 YAML</a><a class="amcms-security-btn" href="${h(absolutePublicPath('/examples/headless-next/README.md', siteId))}" target="_blank" rel="noopener">Next</a><a class="amcms-security-btn" href="${h(absolutePublicPath('/examples/headless-nuxt/README.md', siteId))}" target="_blank" rel="noopener">Nuxt</a><a class="amcms-security-btn" href="${h(absolutePublicPath('/examples/headless-astro/README.md', siteId))}" target="_blank" rel="noopener">Astro</a><a class="amcms-security-btn" href="${h(absolutePublicPath('/examples/headless-vanilla/README.md', siteId))}" target="_blank" rel="noopener">Vanilla</a></div>`;
  }
  function headlessIntroHtml(siteId, state){
    const apiUrl = publicApiBaseUrl(siteId);
    const site = selectedSiteKey(siteId);
    const lang = selectedLang();
    const tokens = Array.isArray(state?.tokens) ? state.tokens : [];
    const activeTokens = tokens.filter(token => token?.is_active);
    const cors = (state?.cors || []).find(c => Number(c.site_id) === Number(siteId)) || {origins:[]};
    const corsOrigins = Array.isArray(cors.origins) ? cors.origins : [];
    const tokenHelp = activeTokens.length
      ? `<div class="amcms-headless-note ok"><strong>Token prêt</strong><br>${activeTokens.length} token(s) actif(s) existent pour ce site. Les secrets ne sont jamais réaffichés : transmettez uniquement un nouveau token copié au moment de sa création.</div>`
      : `<div class="amcms-headless-note warning"><strong>Aucun token actif</strong><br>Créez un token avec un scope de lecture, puis copiez immédiatement sa valeur. Le back-office ne réaffiche jamais les tokens existants en clair.</div>`;
    const corsHelp = corsOrigins.length
      ? `<div class="amcms-headless-note ok"><strong>CORS configuré</strong><br>${corsOrigins.length} origine(s) autorisée(s) dans l’onglet <strong>Relations</strong>. CORS ne remplace pas l’authentification Bearer Token.</div>`
      : `<div class="amcms-headless-note warning"><strong>CORS à vérifier</strong><br>Pour un front appelé depuis un navigateur, ajoutez son origine exacte dans <strong>Configuration > Relations</strong>. Exemple : <code>https://www.example.ch</code>.</div>`;
    const fetchExample = [
      `const AMCMS_BASE_URL = "${apiUrl.replace(/\/api\/v1$/, '')}";`,
      'const AMCMS_TOKEN = "amcms_xxx";',
      `const AMCMS_SITE = "${site}";`,
      `const AMCMS_LANG = "${lang}";`,
      '',
      'const params = new URLSearchParams({',
      '  site: AMCMS_SITE,',
      '  lang: AMCMS_LANG,',
      '  path: "/"',
      '});',
      '',
      'const response = await fetch(`${AMCMS_BASE_URL}/api/v1/route?${params}`, {',
      '  headers: {',
      '    Accept: "application/json",',
      '    Authorization: `Bearer ${AMCMS_TOKEN}`',
      '  }',
      '});',
      '',
      'const payload = await response.json();',
      'if (!response.ok) {',
      '  throw new Error(payload?.error?.message || `DEC CMS API ${response.status}`);',
      '}',
      'console.log(payload.data);'
    ].join('\n');
    const sdkExample = `import { createAmCmsClient, AmCmsApiError } from "@amcms/client";\n\nconst client = createAmCmsClient({\n  baseUrl: process.env.AMCMS_BASE_URL ?? "${apiUrl.replace(/\/api\/v1$/, '')}",\n  token: process.env.AMCMS_TOKEN ?? "amcms_xxx",\n  site: process.env.AMCMS_SITE ?? "${site}",\n  lang: process.env.AMCMS_LANG ?? "${lang}"\n});\n\ntry {\n  const route = await client.getRoute("/");\n  const menu = await client.getMenu("primary");\n  const articles = await client.getContent("article");\n  const results = await client.search("test");\n  console.log({ route, menu, articles, results });\n} catch (error) {\n  if (error instanceof AmCmsApiError) {\n    console.error(error.status, error.code, error.message);\n  } else {\n    console.error(error);\n  }\n}`;
    const quickTest = `curl -H "Authorization: Bearer amcms_xxx" \\\n  "${apiUrl}/health"\n\ncurl -H "Authorization: Bearer amcms_xxx" \\\n  "${apiUrl}/route?site=${encodeURIComponent(site)}&lang=${encodeURIComponent(lang)}&path=/"`;
    return `<section class="amcms-security-section amcms-headless-docs"><h4>API headless publique</h4><p class="amcms-security-muted">Cette page centralise les informations à transmettre à un développeur front-end tiers. Elle concerne uniquement l’API publique <code>/api/v1/*</code> : seuls les contenus publiés sont exposés. Les brouillons, la preview avancée, les mutations publiques, GraphQL et l’API admin ne sont pas disponibles ici.</p>${tokenHelp}${corsHelp}<div class="amcms-headless-kv"><strong>API publique</strong><code>${h(apiUrl)}</code><strong>Documentation</strong><code>${h(publicDocsUrl('index.html', siteId))}</code><strong>OpenAPI JSON</strong><code>${h(openApiUrl(siteId))}</code><strong>OpenAPI YAML</strong><code>${h(openApiYamlUrl(siteId))}</code><strong>Variables utiles</strong><span><code>AMCMS_BASE_URL</code> · <code>AMCMS_TOKEN</code> · <code>AMCMS_SITE</code> · <code>AMCMS_LANG</code></span><strong>Contexte conseillé</strong><span><code>site</code> = ${h(site)} · <code>site_id</code> = ${h(siteId)} · <code>lang</code> = ${h(lang)}</span></div>${headlessDocLinksHtml(siteId)}<div><strong>Authentification Bearer Token</strong><p class="amcms-security-muted">Créez un token avec les scopes nécessaires, copiez sa valeur une seule fois, puis transmettez-la au développeur via une variable d’environnement. Les tokens stockés ne sont jamais affichés en clair.</p></div><div><strong>Scopes de lecture utiles</strong><div class="amcms-headless-scope-list">${headlessScopes(state).map(scope => `<span class="amcms-security-pill">${h(scope)}</span>`).join('')}</div></div><div><strong>CORS</strong><p class="amcms-security-muted">CORS autorise les domaines front-end à appeler l’API depuis un navigateur. La configuration reste dans <strong>Configuration > Relations</strong>, car elle décrit la relation entre ce CMS et les fronts autorisés.</p></div>${codeBlock('Exemple fetch copiable', fetchExample)}${codeBlock('Exemple SDK TypeScript copiable', sdkExample)}${codeBlock('Tester rapidement l’API', quickTest)}</section>`;
  }
  function headlessWebhookHelpHtml(siteId){
    return `<section class="amcms-security-section amcms-headless-docs"><h4>Usage headless côté front-end</h4><p class="amcms-security-muted">Les webhooks servent à notifier un front statique ou un cache quand un contenu est publié, dépublié ou mis à jour. Ils ne donnent pas accès à l’API admin et ne remplacent pas les tokens Bearer de lecture publique.</p><ul class="amcms-headless-inline-list"><li><code>content.published</code> : déclencher un rebuild ou une purge de cache après publication.</li><li><code>content.unpublished</code> : retirer une page ou un article du front.</li><li><code>content.updated</code> : synchroniser un front ou un index externe après mise à jour.</li></ul>${headlessDocLinksHtml(siteId)}</section>`;
  }
  function corsHeadlessHelpHtml(siteId, state){
    const cors = (state?.cors || []).find(c => Number(c.site_id) === Number(siteId)) || {origins:[]};
    const origins = Array.isArray(cors.origins) ? cors.origins : [];
    const originsHtml = origins.length ? origins.map(origin => `<li><code>${h(origin)}</code></li>`).join('') : '<li class="amcms-security-muted">Aucune origine spécifique configurée pour ce site.</li>';
    return `<section data-amcms-cors-relations="1" class="amcms-security-card"><h3 class="amcms-security-title">CORS par site — API headless publique</h3><p class="amcms-security-muted">CORS indique quels domaines front-end ont le droit d’appeler l’API publique depuis un navigateur. Ajoutez ici les origines exactes de vos fronts, par exemple <code>https://www.example.ch</code>. Un token Bearer reste nécessaire si l’endpoint le demande.</p><div class="amcms-headless-note"><strong>Exposition publique</strong><br>Seuls les contenus publiés sont exposés par l’API headless v1. Les contenus brouillons, l’administration et les mutations publiques ne sont pas exposés.</div><h4>Origines CORS actuelles</h4><ul class="amcms-headless-inline-list">${originsHtml}</ul><div class="amcms-headless-kv"><strong>URL de base API</strong><a href="${h(publicApiBaseUrl(siteId))}" target="_blank" rel="noopener"><code>${h(publicApiBaseUrl(siteId))}</code></a><strong>OpenAPI JSON</strong><code>${h(openApiUrl(siteId))}</code><strong>OpenAPI YAML</strong><code>${h(openApiYamlUrl(siteId))}</code></div>${headlessDocLinksHtml(siteId)}</section>`;
  }

  function injectStyles(){
    if (document.getElementById('amcms-security-integrated-style')) return;
    const style = document.createElement('style');
    style.id = 'amcms-security-integrated-style';
    style.textContent = `
      .amcms-security-card{margin:1rem 0;padding:1rem;border:1px solid #e5e7eb;border-radius:1rem;background:#fff;color:#172033}
      .amcms-security-card h3{margin:.1rem 0 .35rem;font-size:1rem;font-weight:500}.amcms-security-card p{margin:.25rem 0 .65rem}
      .amcms-security-layout{display:grid;gap:.85rem}.amcms-security-section{border:1px solid #e2e8f0;border-radius:.9rem;background:#fff;padding:.75rem}.amcms-security-section h4{margin:.05rem 0 .55rem;font-size:.95rem;font-weight:600}.amcms-security-section-header{display:block;margin-bottom:.45rem}
      .amcms-security-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:.75rem;align-items:end}.amcms-security-field{display:grid;gap:.25rem;margin:.25rem 0}
      .amcms-security-field input,.amcms-security-field textarea,.amcms-security-field select{border:1px solid #cbd5e1;border-radius:8px;padding:.45rem .55rem;font:inherit;background:white}.amcms-security-field textarea{min-height:96px}
      .amcms-security-actions{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.35rem;align-self:end;align-items:center}.amcms-security-btn{border:1px solid #cbd5e1;border-radius:.75rem;padding:.5rem .85rem;min-height:2.45rem;font:inherit;font-weight:700;cursor:pointer;background:#fff;color:#0f172a;text-decoration:none;line-height:1.2;display:inline-flex;align-items:center;justify-content:center}
      .amcms-security-btn:hover{background:#f8fafc;border-color:#9fb3ce}.amcms-security-btn.primary{background:#0f172a;border-color:#0f172a;color:white}.amcms-security-btn.danger{background:#fff;color:#991b1b;border-color:#fecaca}.amcms-security-btn[aria-selected="true"]{background:#fff;color:#0f172a;box-shadow:0 1px 5px rgba(15,23,42,.08)}
      .amcms-security-pill{display:inline-block;border-radius:999px;padding:.12rem .45rem;font-size:.76rem;font-weight:600;background:#e2e8f0}.amcms-security-pill.ok{background:#dcfce7;color:#166534}.amcms-security-pill.off{background:#fee2e2;color:#991b1b}
      .amcms-security-list{display:grid;gap:.35rem}.amcms-security-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.75rem;align-items:start;border-top:1px solid #e2e8f0;padding:.55rem 0}.amcms-security-muted{color:#64748b}.amcms-security-secret{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#eef2ff;padding:.4rem;border-radius:8px;word-break:break-all}
      .amcms-security-copy-row{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin:.45rem 0}.amcms-security-copy-row .amcms-security-secret{flex:1;min-width:220px}
      .amcms-security-mini-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.45rem;margin:.15rem 0 .6rem;width:100%}.amcms-security-stat{border:1px solid #e2e8f0;border-radius:.65rem;padding:.38rem .45rem;background:#f8fafc}.amcms-security-stat strong{display:block;font-size:.98rem}.amcms-security-deliveries{margin-top:.55rem;border:1px solid #e2e8f0;border-radius:.75rem;padding:.65rem;background:#f8fafc}.amcms-security-delivery{border-top:1px solid #e2e8f0;padding:.5rem 0}.amcms-security-delivery:first-child{border-top:0}.amcms-security-pre{white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#fff;border-radius:.5rem;padding:.4rem;margin:.35rem 0 0}
      .amcms-security-setup{border:1px dashed #bfdbfe;border-radius:14px;background:#fff;padding:.85rem;margin-top:.75rem}.amcms-security-setup h4{margin:.1rem 0 .55rem}.amcms-security-code-input{max-width:10rem;text-align:center;letter-spacing:.16em;font-weight:700}
      [data-amcms-settings-security-panel]{margin-top:1rem}.amcms-native-settings-hidden{display:none!important}
      .amcms-security-title{display:flex;align-items:center;gap:.45rem;flex-wrap:wrap;font-size:1rem;font-weight:500}.amcms-security-field .native-label-with-hint,.amcms-security-title .native-label-with-hint{align-items:center;gap:.25rem;min-width:0;display:inline-flex}.amcms-security-card .info-hint{position:relative;display:inline-flex;vertical-align:middle;flex:none}.amcms-security-card .info-hint__button{display:inline-flex;align-items:center;justify-content:center;width:1.25rem;height:1.25rem;border-radius:999px;border:1px solid #cbd5e1;background:#fff;color:#334155;font-size:.78rem;font-weight:700;line-height:1;cursor:help}.amcms-security-card .info-hint__popover{display:none;position:absolute;z-index:20;top:1.65rem;left:0;min-width:240px;max-width:360px;padding:.65rem .75rem;border:1px solid #dbe3ef;border-radius:.75rem;background:#fff;box-shadow:0 12px 30px rgba(15,23,42,.14);font-size:.86rem;color:#334155}.amcms-security-card .info-hint:focus-within .info-hint__popover,.amcms-security-card .info-hint:hover .info-hint__popover{display:block}.amcms-security-field input[type="datetime-local"]{min-width:16rem}
      .amcms-headless-docs{display:grid;gap:.7rem}.amcms-headless-kv{display:grid;grid-template-columns:minmax(120px,180px) minmax(0,1fr);gap:.35rem .75rem;margin:.45rem 0}.amcms-headless-kv code,.amcms-headless-code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}.amcms-headless-code{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#f8fafc;border-radius:.75rem;padding:.75rem;margin:.45rem 0;font-size:.84rem;line-height:1.45}.amcms-headless-links{display:flex;flex-wrap:wrap;gap:.45rem}.amcms-headless-scope-list{display:flex;flex-wrap:wrap;gap:.35rem;margin:.45rem 0}.amcms-headless-note{border-left:3px solid #0f172a;background:#f8fafc;padding:.55rem .7rem;border-radius:.35rem}.amcms-headless-note.warning{border-left-color:#d97706;background:#fffbeb}.amcms-headless-note.ok{border-left-color:#16a34a;background:#f0fdf4}.amcms-headless-code-card{border:1px solid #e2e8f0;border-radius:.85rem;padding:.65rem;background:#fff}.amcms-headless-code-card h4{margin:.05rem 0 .35rem}.amcms-headless-inline-list{margin:.35rem 0 .2rem;padding-left:1.15rem}.amcms-headless-inline-list li{margin:.15rem 0}
      @media (max-width:720px){.amcms-security-mini-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.amcms-headless-kv{grid-template-columns:1fr}}
    `;
    document.head.appendChild(style);
  }

  async function ensureContext(){
    injectStyles();
    if (!adminContext) {
      try {
        adminContext = await api('/context' + (querySiteId() > 0 ? `?site_id=${querySiteId()}` : ''));
        csrfToken = adminContext.csrf_token || csrfToken || '';
      } catch(e) {}
    }
  }

  async function loadSecurity(siteId){
    await ensureContext();
    if (securityState && Number(securityStateSiteId) === Number(siteId)) return securityState;
    securityState = await api(`/security?site_id=${encodeURIComponent(siteId)}`);
    securityStateSiteId = Number(siteId);
    return securityState;
  }

  async function loadUsers(){
    await ensureContext();
    const d = await api('/iam/users?limit=100');
    usersCache = d.users || [];
    return usersCache;
  }

  function showAdminToast(text, ok=true){
    const value = String(text || '').trim();
    if (!value) return;
    let container = document.querySelector('[data-amcms-security-toast-container]');
    if (!container) {
      container = document.createElement('div');
      container.className = 'toast-container position-fixed top-0 start-50 translate-middle-x p-3 admin-toast-container';
      container.setAttribute('data-amcms-security-toast-container', '');
      container.style.pointerEvents = 'none';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast show border-0 shadow-lg admin-toast ${ok ? 'text-bg-success' : 'text-bg-danger'}`;
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    toast.setAttribute('aria-atomic', 'true');
    toast.style.pointerEvents = 'auto';
    toast.innerHTML = `<div class="admin-toast__content"><span class="admin-toast__icon" aria-hidden="true">${ok ? '✓' : '!'}</span><div class="admin-toast__text"><strong>${ok ? 'Succès' : 'Erreur'}</strong><span>${h(value)}</span></div><button type="button" class="btn-close btn-close-white admin-toast__close" aria-label="Fermer"></button></div>`;

    const remove = () => {
      toast.remove();
      if (container && !container.children.length) container.remove();
    };
    toast.querySelector('button')?.addEventListener('click', remove);
    container.appendChild(toast);
    window.setTimeout(remove, ok ? 3600 : 7000);
  }

  function message(root, text, ok=true){
    const box = root.querySelector('[data-amcms-security-message]');
    if (box) { box.textContent = text; box.style.color = ok ? '#166534' : '#991b1b'; }
  }

  function formatSecret(secret){
    return String(secret || '').replace(/\s+/g, '').replace(/(.{4})/g, '$1 ').trim();
  }

  async function copyText(text){
    const value = String(text || '');
    if (!value) return false;
    try {
      if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(value); return true; }
    } catch(e) {}
    const area = document.createElement('textarea');
    area.value = value;
    area.setAttribute('readonly', 'readonly');
    area.style.position = 'fixed';
    area.style.left = '-9999px';
    document.body.appendChild(area);
    area.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch(e) { ok = false; }
    area.remove();
    return ok;
  }

  // Modifier l’utilisateur > Configuration avancée : activation/désactivation de la connexion par code email.
  function currentEditorEmail(){
    const title = [...document.querySelectorAll('h1,h2,h3,summary')].find(e => /Modifier l[’']utilisateur/i.test(e.textContent || ''));
    const scope = title?.closest('aside,section,main,div') || document.querySelector('main') || document;
    const inputs = [...scope.querySelectorAll('input[type="email"], input[name="email"], input[id="email"]'), ...document.querySelectorAll('input[type="email"], input[name="email"], input[id="email"]')];
    const input = inputs.find(i => String(i.value || '').includes('@')) || inputs[0];
    return input ? String(input.value || '').trim().toLowerCase() : '';
  }

  function findUserAdvancedContainer(){
    const explicit = document.querySelector('details.iam-advanced-config, [data-iam-user-advanced], [data-user-advanced-config]');
    if (explicit) return explicit;
    return [...document.querySelectorAll('details,section,fieldset,div.card,article')]
      .find(d => /Configuration avancée/i.test(d.textContent || '') && !!d.querySelector('input[name="locale"], input[id="locale"], input[name="email"], input[id="email"]'));
  }

  async function injectTotpInUserAdvanced(){
    // Native IAM users view owns login_mode/password/email_code/totp controls.
    // The former injected email-code control is intentionally deprecated.
  }


  function findSettingsTabBar(){
    if (!isSettingsRoute()) return null;
    const explicit = document.querySelector('nav.editor-tabs[aria-label="Configuration"], [data-amcms-settings-tabs]');
    if (explicit) return explicit;
    const candidates = [...document.querySelectorAll('nav[aria-label="Configuration"], [role="tablist"][aria-label="Configuration"], .config-page > .editor-tabs')];
    return candidates.find(el => {
      const directControls = [...el.children].filter(x => x.matches?.('button,a,[role="tab"]'));
      const text = (el.textContent || '').replace(/\s+/g, ' ');
      if (!/Multisite/.test(text) || !/Médias|Media/.test(text) || !/Relations/.test(text)) return false;
      return directControls.some(x => /Médias|Media/.test(controlText(x))) && directControls.some(x => /Relations/.test(controlText(x)));
    }) || null;
  }

  function controlText(control){ return (control.textContent || '').replace(/\s+/g, ' ').trim(); }

  function cloneTabButton(source, label, key){
    const button = source.cloneNode(true);
    button.textContent = label;
    button.dataset.amcmsSettingsSecurityTab = key;
    if (button.tagName === 'A') { button.removeAttribute('href'); button.setAttribute('role', 'tab'); }
    button.setAttribute('type', 'button');
    button.setAttribute('aria-selected', activeSiteSecurityTab === key ? 'true' : 'false');
    button.classList.remove('router-link-active','router-link-exact-active','active','is-active');
    if (activeSiteSecurityTab === key) button.classList.add('active');
    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      activeSiteSecurityTab = key;
      renderSiteSecurityTab(key);
    });
    return button;
  }

  function insertSettingsSecurityTabs(){
    const bar = findSettingsTabBar();
    if (!bar || bar.querySelector('[data-amcms-settings-security-tab="tokens"]')) return null;
    const controls = [...bar.querySelectorAll(':scope > button, :scope > a, :scope > [role="tab"]')];
    const media = controls.find(c => /Médias|Media/.test(controlText(c)));
    const relations = controls.find(c => /Relations/.test(controlText(c)));
    if (!media || !relations) return null;
    const tokenTab = canReadTokens() ? cloneTabButton(media, 'API', 'tokens') : null;
    const webhookTab = canReadWebhooks() ? cloneTabButton(media, 'Webhooks', 'webhooks') : null;
    if (webhookTab) media.insertAdjacentElement('afterend', webhookTab);
    if (tokenTab) media.insertAdjacentElement('afterend', tokenTab);
    relations.addEventListener('click', () => { activeSiteSecurityTab = null; setTimeout(injectCorsInRelations, 220); });
    controls.filter(c => c !== relations).forEach(c => c.addEventListener('click', () => { activeSiteSecurityTab = null; removeSettingsSecurityPanel(); showNativeSettingsContent(); removeCorsRelationsPanel(); }));
    return bar;
  }

  function settingsPanelContainer(){
    const bar = findSettingsTabBar();
    if (!bar) return null;
    let panel = document.querySelector('[data-amcms-settings-security-panel]');
    if (!panel) {
      panel = document.createElement('section');
      panel.dataset.amcmsSettingsSecurityPanel = '1';
      panel.className = 'amcms-security-card';
      bar.insertAdjacentElement('afterend', panel);
    }
    return panel;
  }

  function removeSettingsSecurityPanel(){
    document.querySelector('[data-amcms-settings-security-panel]')?.remove();
    document.querySelectorAll('[data-amcms-settings-security-tab]').forEach(btn => { btn.setAttribute('aria-selected', 'false'); btn.classList.remove('active','is-active'); });
  }

  function removeCorsRelationsPanel(){
    document.querySelector('[data-amcms-cors-relations]')?.remove();
  }

  function isRelationsTabActive(){
    const bar = findSettingsTabBar();
    if (!bar) return false;
    const controls = [...bar.querySelectorAll(':scope > button, :scope > a, :scope > [role="tab"]')];
    return controls.some(c => /Relations/.test(controlText(c)) && (c.getAttribute('aria-selected') === 'true' || c.classList.contains('active') || c.classList.contains('is-active')));
  }

  function nativeSettingsContentNodes(){
    const panel = document.querySelector('[data-amcms-settings-security-panel]');
    const bar = findSettingsTabBar();
    const start = panel || bar;
    if (!start || !start.parentElement) return [];
    const siblings = [...start.parentElement.children];
    const index = siblings.indexOf(start);
    return siblings.slice(index + 1).filter(el => !el.hasAttribute('data-amcms-settings-security-panel'));
  }

  function hideNativeSettingsContent(){ nativeSettingsContentNodes().forEach(el => el.classList.add('amcms-native-settings-hidden')); }
  function showNativeSettingsContent(){ document.querySelectorAll('.amcms-native-settings-hidden').forEach(el => el.classList.remove('amcms-native-settings-hidden')); }

  async function renderSiteSecurityTab(key, force=false){
    if (!isSettingsRoute()) return;
    if ((key === 'tokens' && !canReadTokens()) || (key === 'webhooks' && !canReadWebhooks())) return;
    const siteId = selectedSiteId();
    const state = await loadSecurity(siteId).catch(() => null);
    if (!state) return;
    const panel = settingsPanelContainer();
    if (!panel) return;
    if (!force && panel.dataset.securityTab === key && Number(panel.dataset.siteId || 0) === Number(siteId)) return;
    panel.dataset.securityTab = key;
    panel.dataset.siteId = String(siteId);
    hideNativeSettingsContent();
    document.querySelectorAll('[data-amcms-settings-security-tab]').forEach(btn => {
      const selected = btn.dataset.amcmsSettingsSecurityTab === key;
      btn.setAttribute('aria-selected', selected ? 'true' : 'false');
      btn.classList.toggle('active', selected);
      btn.classList.toggle('is-active', selected);
    });
    const bar = findSettingsTabBar();
    bar?.querySelectorAll(':scope > button:not([data-amcms-settings-security-tab]), :scope > a:not([data-amcms-settings-security-tab]), :scope > [role="tab"]:not([data-amcms-settings-security-tab])').forEach(btn => {
      btn.classList.remove('active','is-active');
      if (btn.hasAttribute('aria-selected')) btn.setAttribute('aria-selected', 'false');
    });
    removeCorsRelationsPanel();
    panel.className = 'amcms-security-card';
    panel.innerHTML = key === 'tokens' ? tokensHtml(siteId, state) : webhooksHtml(siteId, state);
    bindSiteSecurity(panel, siteId);
  }

  function tokenStats(tokens){
    const total = tokens.length;
    const active = tokens.filter(t => t.is_active).length;
    const inactive = total - active;
    const neverUsed = tokens.filter(t => !t.last_used_at).length;
    return {total, active, inactive, neverUsed};
  }

  function statsGrid(items){
    return `<div class="amcms-security-mini-grid">${items.map(item => `<div class="amcms-security-stat"><strong>${h(item.value)}</strong><span>${h(item.label)}</span></div>`).join('')}</div>`;
  }

  function tokensHtml(siteId, state){
    const tokens = state.tokens || [];
    const stats = tokenStats(tokens);
    const title = blueprintLabel(state, 'security_api_token', 'API');
    const description = blueprintDescription(state, 'security_api_token', SECURITY_BLUEPRINT_HELP.tokens);
    const name = describedInput(state, 'security_api_token', 'name', `<input name="name" required placeholder="Front statique production">`);
    const scopesDefault = fieldDefault(state, 'security_api_token', 'scopes', 'headless:read');
    const scopes = describedInput(state, 'security_api_token', 'scopes', `<input name="scopes" value="${h(scopesDefault)}">`);
    const expires = describedInput(state, 'security_api_token', 'expires_at', `<input name="expires_at" type="datetime-local">`);
    const createForm = canManageTokens() ? `<section class="amcms-security-section"><form data-token-form class="amcms-security-grid">${name}${scopes}${expires}<div class="amcms-security-actions"><button type="submit" class="amcms-security-btn primary">Créer</button></div></form></section>` : '';
    const testForm = canManageTokens() ? `<section class="amcms-security-section"><form data-token-test-form class="amcms-security-grid"><label class="amcms-security-field"><span>Secret à tester</span><input name="token" autocomplete="off" placeholder="amcms_…"></label><label class="amcms-security-field"><span>Scope attendu</span><input name="scope" value="headless:read"></label><div class="amcms-security-actions"><button type="submit" class="amcms-security-btn">Tester</button></div></form><div data-token-test-result class="amcms-security-muted"></div></section>` : '';
    const list = `<section class="amcms-security-section">${statsGrid([{label:'tokens',value:stats.total},{label:'actifs',value:stats.active},{label:'inactifs',value:stats.inactive},{label:'jamais utilisés',value:stats.neverUsed}])}<div class="amcms-security-list">${tokens.map(t=>`<div class="amcms-security-row"><div><strong>${h(t.name)}</strong> <span class="amcms-security-pill ${t.is_active?'ok':'off'}">${t.is_active?'actif':'inactif'}</span><br><span class="amcms-security-muted">${h(fieldLabel(state, 'security_api_token', 'scopes', 'Scopes'))} : ${h(t.scopes)}${t.expires_at ? ' · ' + h(fieldLabel(state, 'security_api_token', 'expires_at', 'Expiration UTC')) + ' : ' + h(t.expires_at) : ''} · last_used_at : ${t.last_used_at ? h(t.last_used_at) : 'jamais'}</span></div>${canManageTokens() ? `<div class="amcms-security-actions"><button type="button" class="amcms-security-btn" data-token-toggle="${t.id}" data-active="${t.is_active?0:1}">${t.is_active?'Désactiver':'Activer'}</button><button type="button" class="amcms-security-btn danger" data-token-delete="${t.id}">Supprimer</button></div>` : ''}</div>`).join('') || '<p class="amcms-security-muted">Aucun token API pour ce site.</p>'}</div></section>`;
    return `<h3 class="amcms-security-title">${labelWithHint(title, description)}</h3><div data-amcms-security-message class="amcms-security-muted"></div><div class="amcms-security-layout">${canManageTokens() ? createForm + testForm : '<p class="amcms-security-muted">Lecture seule.</p>'}${list}${headlessIntroHtml(siteId, state)}</div>`;
  }

  function webhooksHtml(siteId, state){
    const webhooks = state.webhooks || [];
    const stats = state.webhook_stats || {};
    const title = blueprintLabel(state, 'security_webhook', 'Webhooks');
    const description = blueprintDescription(state, 'security_webhook', SECURITY_BLUEPRINT_HELP.webhooks);
    const eventsDefault = fieldDefault(state, 'security_webhook', 'events', 'content.published content.unpublished content.updated');
    const name = describedInput(state, 'security_webhook', 'name', `<input name="name" placeholder="Rebuild front statique">`);
    const url = describedInput(state, 'security_webhook', 'url', `<input name="url" required placeholder="https://front.example.ch/rebuild">`);
    const events = describedInput(state, 'security_webhook', 'events', `<input name="events" value="${h(eventsDefault)}">`);
    const secret = describedInput(state, 'security_webhook', 'secret', `<input name="secret" placeholder="auto si vide">`);
    const maxAttempts = describedInput(state, 'security_webhook', 'max_attempts', `<input name="max_attempts" type="number" min="1" max="25" value="${h(fieldDefault(state, 'security_webhook', 'max_attempts', '5'))}">`);
    const createForm = canManageWebhooks() ? `<section class="amcms-security-section"><form data-webhook-form class="amcms-security-grid">${name}${url}${events}${secret}${maxAttempts}<div class="amcms-security-actions"><button type="submit" class="amcms-security-btn primary">Créer</button></div></form></section>` : '';
    const ops = canManageWebhooks() ? `<section class="amcms-security-section"><form data-webhook-cleanup-form class="amcms-security-grid"><label class="amcms-security-field"><span>Historique plus ancien que</span><input name="older_than_days" type="number" min="0" max="3650" value="30"></label><label class="amcms-security-field"><span>Statuts à nettoyer</span><input name="statuses" value="succeeded failed"></label><div class="amcms-security-actions"><button type="submit" class="amcms-security-btn danger">Nettoyer</button></div></form></section>` : '';
    const listStats = statsGrid([{label:'livraisons',value:Number(stats.total||0)},{label:'en attente',value:Number(stats.pending||0)},{label:'réussies',value:Number(stats.succeeded||0)},{label:'échouées',value:Number(stats.failed||0)}]);
    const list = `<section class="amcms-security-section">${listStats}<div class="amcms-security-list">${webhooks.map(w=>`<div class="amcms-security-row"><div><strong>${h(w.name || w.url)}</strong> <span class="amcms-security-pill ${w.is_active?'ok':'off'}">${w.is_active?'actif':'inactif'}</span><br><span class="amcms-security-muted">${h(w.url)} · ${h((w.events||[]).join(', '))} · last_attempt_at : ${w.last_attempt_at ? h(w.last_attempt_at) : 'jamais'}</span><div data-webhook-deliveries="${w.id}" class="amcms-security-deliveries" hidden></div></div>${canManageWebhooks() ? `<div class="amcms-security-actions"><button type="button" class="amcms-security-btn" data-webhook-deliveries-btn="${w.id}">Historique</button><button type="button" class="amcms-security-btn" data-webhook-ping="${w.id}">Tester</button><button type="button" class="amcms-security-btn" data-webhook-toggle="${w.id}" data-active="${w.is_active?0:1}">${w.is_active?'Désactiver':'Activer'}</button><button type="button" class="amcms-security-btn danger" data-webhook-delete="${w.id}">Supprimer</button></div>` : `<div class="amcms-security-actions"><button type="button" class="amcms-security-btn" data-webhook-deliveries-btn="${w.id}">Historique</button></div>`}</div>`).join('') || '<p class="amcms-security-muted">Aucun webhook pour ce site.</p>'}</div></section>`;
    return `<h3 class="amcms-security-title">${labelWithHint(title, description)}</h3><div data-amcms-security-message class="amcms-security-muted"></div><div class="amcms-security-layout">${canManageWebhooks() ? createForm + ops : '<p class="amcms-security-muted">Lecture seule.</p>'}${list}${headlessWebhookHelpHtml(siteId)}</div>`;
  }

  function deliveryDiagnosticHtml(d){
    const error = String(d.last_error || '');
    if (/certificate|SSL|subject name|hostname|verify|CN_|Common Name/i.test(error)) {
      return '<div class="amcms-security-muted">Diagnostic : le serveur répond, mais le certificat TLS ne correspond probablement pas au nom de domaine appelé. Vérifier le SAN du certificat et le vhost HTTPS.</div>';
    }
    if (/timed out|timeout|Connection refused|Could not resolve|Name or service not known|DNS/i.test(error)) {
      return '<div class="amcms-security-muted">Diagnostic : problème réseau, DNS, pare-feu ou endpoint indisponible.</div>';
    }
    if (Number(d.http_status || 0) >= 400) {
      return '<div class="amcms-security-muted">Diagnostic : l’endpoint est joignable, mais refuse la requête ou retourne une erreur applicative.</div>';
    }
    return '';
  }

  function deliveryHtml(d){
    const statusClass = d.status === 'succeeded' ? 'ok' : (d.status === 'failed' ? 'off' : '');
    const detail = d.last_error ? `<div class="amcms-security-pre">${h(d.last_error)}</div>` : (d.response_body ? `<div class="amcms-security-pre">${h(d.response_body)}</div>` : '');
    return `<div class="amcms-security-delivery"><strong>${h(d.event_topic)}</strong> <span class="amcms-security-pill ${statusClass}">${h(d.status)}</span> ${d.http_status ? `<span class="amcms-security-muted">HTTP ${h(d.http_status)}</span>` : ''}<br><span class="amcms-security-muted">${h(d.delivery_id)} · essais : ${Number(d.attempts||0)} · création : ${h(d.created_at || '')}${d.delivered_at ? ' · livré : ' + h(d.delivered_at) : ''}</span>${detail}${deliveryDiagnosticHtml(d)}</div>`;
  }

  async function openWebhookDeliveries(root, siteId, id, { forceOpen = false } = {}){
    const box = root.querySelector(`[data-webhook-deliveries="${CSS.escape(String(id))}"]`);
    if (!box) return null;
    if (!forceOpen && !box.hidden) { box.hidden = true; return box; }
    box.hidden = false;
    box.innerHTML = '<span class="amcms-security-muted">Chargement…</span>';
    try {
      const d = await api(`/security/webhooks/${id}/deliveries?site_id=${encodeURIComponent(siteId)}&limit=20`);
      const stats = d.stats || {};
      box.innerHTML = `<div class="amcms-security-muted">Total ${Number(stats.total||0)} · réussies ${Number(stats.succeeded||0)} · échouées ${Number(stats.failed||0)} · attente ${Number(stats.pending||0)}</div>${(d.deliveries||[]).map(deliveryHtml).join('') || '<p class="amcms-security-muted">Aucune delivery.</p>'}`;
    } catch(e){
      box.innerHTML = `<span class="amcms-security-muted">${h(e.message)}</span>`;
    }
    return box;
  }

  async function injectCorsInRelations(){
    if (!isSettingsRoute() || activeSiteSecurityTab || !canReadCors()) return;
    if (!isRelationsTabActive()) { removeCorsRelationsPanel(); return; }
    if (document.querySelector('[data-amcms-cors-relations]')) return;
    const siteId = selectedSiteId();
    const state = await loadSecurity(siteId).catch(() => null);
    if (!state) return;
    showNativeSettingsContent();
    removeSettingsSecurityPanel();
    const relationHeading = [...document.querySelectorAll('h2,h3,legend,summary')].reverse().find(el => /Relations/.test(el.textContent || ''));
    const target = relationHeading?.closest('section,form,fieldset,div') || findSettingsTabBar()?.parentElement || document.querySelector('main') || document.body;
    const card = document.createElement('section');
    card.innerHTML = corsHeadlessHelpHtml(siteId, state);
    const node = card.firstElementChild;
    if (node) target.appendChild(node);
  }

  function bindCorsForm(root, siteId){
    if (!canManageCors()) return;
    root.querySelector('[data-cors-form]')?.addEventListener('submit', async e => {
      e.preventDefault();
      try { await api(`/security/cors/${siteId}`, {method:'PATCH', body:dataBody({origins: lines(e.target.elements.origins.value)})}); securityState = null; message(root, 'CORS mis à jour dans Relations.'); } catch(err){ message(root, err.message, false); }
    });
  }

  function bindSiteSecurity(root, siteId){
    root.querySelectorAll('[data-copy-snippet]').forEach(b => b.addEventListener('click', async () => { const ok = await copyText(b.dataset.copySnippet || ''); b.textContent = ok ? 'Copié' : 'Copie impossible'; setTimeout(() => { b.textContent = 'Copier'; }, 1400); }));
    root.querySelector('[data-token-form]')?.addEventListener('submit', async e => {
      e.preventDefault();
      try { const v = Object.fromEntries(new FormData(e.target).entries()); v.site_id = siteId; v.scopes = lines(v.scopes); v.expires_at = datetimeLocalToUtc(v.expires_at); const d = await api('/security/tokens', {method:'POST', body:dataBody(v)}); securityState = null; root.innerHTML = `<h3 class="amcms-security-title">Token API créé</h3><section class="amcms-security-section"><p>Copiez ce token maintenant : il ne sera plus affiché.</p><p class="amcms-security-secret">${h(d.plain_token)}</p><div class="amcms-security-actions"><button type="button" class="amcms-security-btn" data-refresh-tab>Retour aux tokens</button></div></section>`; root.querySelector('[data-refresh-tab]')?.addEventListener('click', () => renderSiteSecurityTab('tokens', true)); } catch(err){ message(root, err.message, false); }
    });
    root.querySelector('[data-webhook-form]')?.addEventListener('submit', async e => {
      e.preventDefault();
      try { const v = Object.fromEntries(new FormData(e.target).entries()); v.site_id = siteId; v.events = lines(v.events); await api('/security/webhooks', {method:'POST', body:dataBody(v)}); securityState = null; renderSiteSecurityTab('webhooks', true); } catch(err){ message(root, err.message, false); }
    });
    root.querySelector('[data-token-test-form]')?.addEventListener('submit', async e => {
      e.preventDefault();
      const result = root.querySelector('[data-token-test-result]');
      try { const v = Object.fromEntries(new FormData(e.target).entries()); v.site_id = siteId; const d = await api('/security/tokens/test', {method:'POST', body:dataBody(v)}); securityState = null; if (result) result.textContent = d.valid ? `OK : ${d.token?.name || 'token'} · last_used_at ${d.token?.last_used_at || ''}` : `KO : ${d.message || d.reason || 'token invalide'}`; if (d.valid) setTimeout(() => renderSiteSecurityTab('tokens', true), 700); } catch(err){ if (result) result.textContent = err.message; message(root, err.message, false); }
    });
    root.querySelector('[data-webhook-cleanup-form]')?.addEventListener('submit', async e => {
      e.preventDefault();
      if (!confirm('Nettoyer les livraisons de webhook correspondant à ces critères ?')) return;
      try { const v = Object.fromEntries(new FormData(e.target).entries()); v.site_id = siteId; v.statuses = lines(v.statuses); v.older_than_days = Number(v.older_than_days || 30); const d = await api('/security/webhooks/deliveries/cleanup', {method:'DELETE', body:dataBody(v)}); securityState = null; message(root, d.message || 'Nettoyage effectué.'); renderSiteSecurityTab('webhooks', true); } catch(err){ message(root, err.message, false); }
    });
    root.querySelectorAll('[data-token-toggle]').forEach(b => b.addEventListener('click', async () => { try { await api(`/security/tokens/${b.dataset.tokenToggle}`, {method:'PATCH', body:dataBody({is_active:Number(b.dataset.active), site_id:siteId})}); securityState=null; renderSiteSecurityTab('tokens', true); } catch(e){ message(root, e.message, false); } }));
    root.querySelectorAll('[data-webhook-toggle]').forEach(b => b.addEventListener('click', async () => { try { await api(`/security/webhooks/${b.dataset.webhookToggle}`, {method:'PATCH', body:dataBody({is_active:Number(b.dataset.active), site_id:siteId})}); securityState=null; renderSiteSecurityTab('webhooks', true); } catch(e){ message(root, e.message, false); } }));
    root.querySelectorAll('[data-webhook-ping]').forEach(b => b.addEventListener('click', async () => {
      const id = b.dataset.webhookPing;
      try {
        const d = await api(`/security/webhooks/${id}/ping`, {method:'POST', body:dataBody({site_id:siteId})});
        securityState = null;
        showAdminToast(d.message || 'Test webhook effectué.', !!d.ok);
        await openWebhookDeliveries(root, siteId, id, { forceOpen: true });
      } catch(e){
        showAdminToast(e.message, false);
        await openWebhookDeliveries(root, siteId, id, { forceOpen: true });
      }
    }));
    root.querySelectorAll('[data-webhook-deliveries-btn]').forEach(b => b.addEventListener('click', async () => {
      await openWebhookDeliveries(root, siteId, b.dataset.webhookDeliveriesBtn, { forceOpen: false });
    }));
    root.querySelectorAll('[data-token-delete]').forEach(b => b.addEventListener('click', async () => { if (!confirm('Supprimer ce token API ?')) return; try { await api(`/security/tokens/${b.dataset.tokenDelete}`, {method:'DELETE', body:dataBody({site_id:siteId})}); securityState=null; renderSiteSecurityTab('tokens', true); } catch(e){ message(root, e.message, false); } }));
    root.querySelectorAll('[data-webhook-delete]').forEach(b => b.addEventListener('click', async () => { if (!confirm('Supprimer ce webhook ?')) return; try { await api(`/security/webhooks/${b.dataset.webhookDelete}`, {method:'DELETE', body:dataBody({site_id:siteId})}); securityState=null; renderSiteSecurityTab('webhooks', true); } catch(e){ message(root, e.message, false); } }));
  }


  function bindSettingsNativeTabDelegation(){
    if (document.documentElement.dataset.amcmsSecurityTabDelegation === '1') return;
    document.documentElement.dataset.amcmsSecurityTabDelegation = '1';
    document.addEventListener('click', (event) => {
      if (!isSettingsRoute()) return;
      const bar = findSettingsTabBar();
      const control = event.target?.closest?.('button,a,[role="tab"]');
      if (!bar || !control || !bar.contains(control) || control.dataset.amcmsSettingsSecurityTab) return;
      activeSiteSecurityTab = null;
      removeSettingsSecurityPanel();
      showNativeSettingsContent();
      removeCorsRelationsPanel();
      if (/Relations/.test(controlText(control))) setTimeout(injectCorsInRelations, 220);
    }, true);
  }

  async function injectSettingsSecurityConfiguration(){
    if (!isSettingsRoute()) return;
    await ensureContext();
    bindSettingsNativeTabDelegation();
    insertSettingsSecurityTabs();
    if (activeSiteSecurityTab) renderSiteSecurityTab(activeSiteSecurityTab);
    else setTimeout(injectCorsInRelations, 100);
  }

  async function init(){
    await ensureContext();
    await injectTotpInUserAdvanced();
    await injectSettingsSecurityConfiguration();
  }

  let timer = null;
  function schedule(){ clearTimeout(timer); timer = setTimeout(init, 250); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
  new MutationObserver(schedule).observe(document.documentElement, {childList:true, subtree:true});
  window.addEventListener('popstate', schedule);
})();

export {};
