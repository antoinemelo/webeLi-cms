import { createRouter, createWebHistory } from 'vue-router';

import DashboardView from '@/views/DashboardView.vue';

// Vue Router accepts lazy route components natively. Keeping only the small
// dashboard eager avoids downloading every administration workspace at login.
const StudioView = () => import('@/views/StudioView.vue');
const ImportsExportsView = () => import('@/views/ImportsExportsView.vue');
const ContentListView = () => import('@/views/content/ContentListView.vue');
const ContentEditorView = () => import('@/views/content/ContentEditorView.vue');
const ShopSystemEditorView = () => import('@/views/content/ShopSystemEditorView.vue');
const MediaLibraryView = () => import('@/views/content/MediaLibraryView.vue');
const DocsView = () => import('@/views/assets/DocsView.vue');
const FormsView = () => import('@/views/forms/FormsView.vue');
const ModulesView = () => import('@/views/modules/ModulesView.vue');
const AiAssistantConfigView = () => import('@/views/modules/AiAssistantConfigView.vue');
const BusinessCrmView = () => import('@/views/modules/BusinessCrmView.vue');
const SaleView = () => import('@/views/modules/SaleView.vue');
const MenusView = () => import('@/views/content/MenusView.vue');
const TaxonomiesView = () => import('@/views/content/TaxonomiesView.vue');
const BlueprintsView = () => import('@/views/system/BlueprintsView.vue');
const SeoAuditView = () => import('@/views/tools/SeoAuditView.vue');
const MaintenanceView = () => import('@/views/tools/MaintenanceView.vue');
const SystemConfigurationView = () => import('@/views/system/SystemConfigurationView.vue');
const CookiesView = () => import('@/views/system/CookiesView.vue');
const IamUsersView = () => import('@/views/iam/IamUsersView.vue');
const IamRolesView = () => import('@/views/iam/IamRolesView.vue');
const IamSessionsView = () => import('@/views/iam/IamSessionsView.vue');
const IamAuditView = () => import('@/views/iam/IamAuditView.vue');
const ProfileView = () => import('@/views/ProfileView.vue');

declare global {
  interface Window {
    __AMCMS_ADMIN__?: {
      basePath?: string;
      adminAppPath?: string;
      apiBasePath?: string;
      siteBasePath?: string;
      logoutPath?: string;
      loginPath?: string;
      user?: { id?: number; email?: string; name?: string };
    };
  }
}

const adminBasePath = (() => {
  const configured = window.__AMCMS_ADMIN__?.adminAppPath;
  if (configured && configured.trim() !== '') {
    return `/${configured.trim().replace(/^\/+|\/+$/g, '')}/`;
  }

  const marker = '/admin/app';
  const pathname = window.location.pathname;
  const index = pathname.indexOf(marker);

  if (index === -1) {
    return `${marker}/`;
  }

  return `${pathname.slice(0, index + marker.length)}/`;
})();

export const router = createRouter({
  history: createWebHistory(adminBasePath),
  routes: [
    { path: '/', name: 'dashboard', component: DashboardView },
    { path: '/studio', name: 'studio', component: StudioView },
    { path: '/static-exports', redirect: '/imports-exports' },
    { path: '/imports-exports', name: 'imports-exports', component: ImportsExportsView },
    { path: '/contents/pages/system-shop', name: 'shop-system-edit', component: ShopSystemEditorView },
    { path: '/contents/:typeKey', name: 'content-list', component: ContentListView, props: true },
    { path: '/contents/:typeKey/new', name: 'content-new', component: ContentEditorView, props: true },
    { path: '/contents/:typeKey/:id', name: 'content-edit', component: ContentEditorView, props: true },
    { path: '/media', name: 'media-library', component: MediaLibraryView },
    { path: '/docs', name: 'docs', component: DocsView },
    { path: '/forms', name: 'forms', component: FormsView },
    { path: '/modules', name: 'modules', component: ModulesView },
    { path: '/modules/ai-assistant/config', name: 'ai-assistant-config', component: AiAssistantConfigView },
    { path: '/business', name: 'business', component: BusinessCrmView, props: { initialTab: 'dashboard' } },
    { path: '/business/relations', name: 'business-relations', component: BusinessCrmView, props: { initialTab: 'relations' } },
    { path: '/business/relations/advanced/profiles', name: 'business-advanced-profiles', component: BusinessCrmView, props: { initialTab: 'relations' } },
    { path: '/business/products-stock', name: 'business-products-stock', component: BusinessCrmView, props: { initialTab: 'products' } },
    { path: '/business/inventory', name: 'business-inventory', component: BusinessCrmView, props: { initialTab: 'inventory' } },
    { path: '/business/offers-marketing', name: 'business-offers-marketing', component: BusinessCrmView, props: { initialTab: 'offers' } },
    { path: '/business/offers-marketing/storytelling', name: 'business-storytelling', component: BusinessCrmView, props: { initialTab: 'storytelling' } },
    { path: '/business/offers-marketing/audiences', name: 'business-audiences', component: BusinessCrmView, props: { initialTab: 'segments' } },
    { path: '/business/offers-marketing/campaigns', name: 'business-campaigns', component: BusinessCrmView, props: { initialTab: 'messages' } },
    { path: '/business/settings', name: 'business-settings', component: BusinessCrmView, props: { initialTab: 'settings' } },
    { path: '/business/crm', redirect: '/business/relations' },
    { path: '/business/companies', redirect: '/business/relations' },
    { path: '/business/contacts', redirect: '/business/relations' },
    { path: '/business/memos', redirect: '/business/relations' },
    { path: '/business/mailing', redirect: '/business/offers-marketing/campaigns' },
    { path: '/business/messaging', redirect: '/business/offers-marketing/campaigns' },
    { path: '/business/catalog', redirect: '/business/products-stock' },
    { path: '/sale', name: 'sale', component: SaleView },
    { path: '/sale/orders', name: 'sale-orders', component: SaleView },
    { path: '/sale/payments', name: 'sale-payments', component: SaleView },
    { path: '/sale/gift-cards', name: 'sale-gift-cards', component: SaleView },
    { path: '/sale/stock', redirect: '/sale/advanced/stock' },
    { path: '/sale/reservations', redirect: '/sale/advanced/reservations' },
    { path: '/sale/operations', redirect: '/sale/advanced/logistics' },
    { path: '/sale/identities', redirect: to => ({ path: '/business/relations/advanced/profiles', query: to.query }) },
    { path: '/sale/advanced/stock', name: 'sale-advanced-stock', component: SaleView },
    { path: '/sale/advanced/reservations', name: 'sale-advanced-reservations', component: SaleView },
    { path: '/sale/advanced/logistics', name: 'sale-advanced-logistics', component: SaleView },
    { path: '/sale/advanced/payments', name: 'sale-advanced-payments', component: SaleView },
    { path: '/sale/advanced/identities', redirect: to => ({ path: '/business/relations/advanced/profiles', query: to.query }) },
    { path: '/sale/pos', name: 'sale-pos', component: SaleView },
    { path: '/sale/settings', name: 'sale-settings', component: SaleView },
    { path: '/commerce', redirect: to => ({ path: '/sale/settings', query: { ...to.query, section: 'ecommerce' } }) },
    { path: '/modules/commerce', redirect: to => ({ path: '/sale/settings', query: { ...to.query, section: 'ecommerce' } }) },
    { path: '/modules/:moduleKey', name: 'module-detail', component: ModulesView, props: true },
    { path: '/menus', name: 'menus', component: MenusView },
    { path: '/taxonomies', name: 'taxonomies', component: TaxonomiesView },
    { path: '/blueprints', name: 'blueprints', component: BlueprintsView },
    { path: '/seo/audit', name: 'seo-audit', component: SeoAuditView },
    { path: '/maintenance', name: 'maintenance', component: MaintenanceView },
    { path: '/settings', name: 'settings', component: SystemConfigurationView },
    { path: '/cookies', name: 'cookies', component: CookiesView },
    { path: '/iam', redirect: '/iam/users' },
    { path: '/iam/users', name: 'iam-users', component: IamUsersView },
    { path: '/iam/roles', name: 'iam-roles', component: IamRolesView },
    { path: '/iam/sessions', name: 'iam-sessions', component: IamSessionsView },
    { path: '/iam/audit', name: 'iam-audit', component: IamAuditView },
    { path: '/profile', name: 'profile', component: ProfileView },
    { path: '/:pathMatch(.*)*', redirect: '/' }
  ]
});

router.onError((error, to) => {
  const message = String(error?.message || error || '').toLowerCase();
  const isStaleChunkError = [
    'failed to fetch dynamically imported module',
    'error loading dynamically imported module',
    'importing a module script failed',
    'dynamically imported module'
  ].some((needle) => message.includes(needle));

  if (!isStaleChunkError || typeof window === 'undefined') {
    return;
  }

  const target = router.resolve(to).href || window.location.pathname;
  const reloadKey = `amcms.admin.chunk-reload:${target}`;
  if (window.sessionStorage.getItem(reloadKey) === '1') {
    return;
  }

  window.sessionStorage.setItem(reloadKey, '1');
  window.location.assign(target);
});
