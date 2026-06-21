import { createRouter, createWebHistory } from 'vue-router';

import DashboardView from '@/views/DashboardView.vue';
import MediaLibraryView from '@/views/content/MediaLibraryView.vue';
import DocsView from '@/views/assets/DocsView.vue';
import ContentListView from '@/views/content/ContentListView.vue';
import ContentEditorView from '@/views/content/ContentEditorView.vue';
import MenusView from '@/views/content/MenusView.vue';
import TaxonomiesView from '@/views/content/TaxonomiesView.vue';
import StudioView from '@/views/StudioView.vue';
import SystemConfigurationView from '@/views/system/SystemConfigurationView.vue';
import CookiesView from '@/views/system/CookiesView.vue';
import BlueprintsView from '@/views/system/BlueprintsView.vue';
import SeoAuditView from '@/views/tools/SeoAuditView.vue';
import MaintenanceView from '@/views/tools/MaintenanceView.vue';
import ProfileView from '@/views/ProfileView.vue';
import IamUsersView from '@/views/iam/IamUsersView.vue';
import IamRolesView from '@/views/iam/IamRolesView.vue';
import IamSessionsView from '@/views/iam/IamSessionsView.vue';
import IamAuditView from '@/views/iam/IamAuditView.vue';
import FormsView from '@/views/forms/FormsView.vue';
import ModulesView from '@/views/modules/ModulesView.vue';
import AiAssistantConfigView from '@/views/modules/AiAssistantConfigView.vue';
import ImportsExportsView from '@/views/ImportsExportsView.vue';

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
    { path: '/contents/:typeKey', name: 'content-list', component: ContentListView, props: true },
    { path: '/contents/:typeKey/new', name: 'content-new', component: ContentEditorView, props: true },
    { path: '/contents/:typeKey/:id', name: 'content-edit', component: ContentEditorView, props: true },
    { path: '/media', name: 'media-library', component: MediaLibraryView },
    { path: '/docs', name: 'docs', component: DocsView },
    { path: '/forms', name: 'forms', component: FormsView },
    { path: '/modules', name: 'modules', component: ModulesView },
    { path: '/modules/ai-assistant/config', name: 'ai-assistant-config', component: AiAssistantConfigView },
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
