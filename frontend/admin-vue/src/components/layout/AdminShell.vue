<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { canShow, mainNavigation, normalizeModuleNavigation, visibleSectionLinks, type MainNavigationItem, type SectionLink } from '@/router/navigation';
import { useAdminContextStore } from '@/stores/adminContext';
import { useI18n } from '@/i18n';
import ContentLanguageSwitcher from './ContentLanguageSwitcher.vue';
import GlobalSearch from './GlobalSearch.vue';

const context = useAdminContextStore();
const route = useRoute();
const { t } = useI18n();
const menuOpen = ref(false);
const accountMenuOpen = ref(false);

const visibleNavigation = computed(() => mainNavigation.filter((item) => {
  if (canShow(item.permission, context.can, item.anyPermission)) return true;
  // Un espace parent doit rester visible si au moins un écran enfant est autorisé.
  // C'est important pour les modules : le noyau ne connaît pas à l'avance
  // les permissions métier déclarées par chaque provider.
  return visibleChildren(item).length > 0;
}));

function visibleStaticChildren(item: MainNavigationItem): SectionLink[] {
  return visibleSectionLinks(item.children ?? [], context.can).filter((child) => !child.disabled);
}

function visibleModuleProviderChildren(): SectionLink[] {
  return normalizeModuleNavigation(context.moduleNavigation)
    .filter((entry) => canShow(entry.permission, context.can, entry.anyPermission))
    .filter((entry) => !entry.disabled);
}

function visibleChildren(item: MainNavigationItem): SectionLink[] {
  if (item.key !== 'modules') return visibleStaticChildren(item);
  const staticRoutes = new Set(visibleStaticChildren(item).map((child) => child.route));
  return [
    ...visibleStaticChildren(item),
    ...visibleModuleProviderChildren().filter((child) => !staticRoutes.has(child.route))
  ];
}
function hasChildren(item: MainNavigationItem): boolean {
  return visibleChildren(item).length > 0;
}
function canOpenModuleGovernance(): boolean {
  return context.can('modules.read') || context.can('modules.manage');
}
function parentRoute(item: MainNavigationItem): string {
  if (item.key === 'assets' && !context.can('media.read')) {
    return visibleStaticChildren(item)[0]?.route || item.route;
  }
  if (item.key !== 'modules' || canOpenModuleGovernance()) return item.route;
  return visibleModuleProviderChildren()[0]?.route || visibleStaticChildren(item)[0]?.route || item.route;
}
function moduleMenuChildren(item: MainNavigationItem): SectionLink[] {
  if (item.key !== 'modules') return visibleChildren(item);
  const staticChildren = visibleStaticChildren(item).filter((child) => child.route !== '/modules');
  const staticRoutes = new Set(staticChildren.map((child) => child.route));
  return [
    ...staticChildren,
    ...visibleModuleProviderChildren().filter((child) => !staticRoutes.has(child.route))
  ];
}
const isSuperAdmin = computed(() => context.can('*') || context.can('roles.manage') || context.can('users.manage'));
const canAdministerGlobalRoles = computed(() => isSuperAdmin.value && !context.isSiteScopedAdmin);
const siteName = computed(() => context.context?.site.name || 'DEC CMS');
const availableSites = computed(() => context.availableSites);
const currentSiteId = computed(() => context.siteId ?? context.context?.site.id ?? 0);

const logoutAction = computed(() => window.__AMCMS_ADMIN__?.logoutPath || `${window.__AMCMS_ADMIN__?.apiBasePath?.replace(/\/api$/g, '') || ''}/logout`);

function onSiteChange(event: Event) {
  const select = event.target as HTMLSelectElement;
  const value = Number(select.value || 0);
  if (value > 0 && value !== currentSiteId.value) {
    const allowed = window.dispatchEvent(new CustomEvent('amcms:before-site-change', {
      cancelable: true,
      detail: { fromSiteId: currentSiteId.value, toSiteId: value }
    }));
    if (!allowed) {
      select.value = String(currentSiteId.value);
      return;
    }
    context.switchSite(value, route.fullPath);
  }
}

function closeMenus() {
  menuOpen.value = false;
  accountMenuOpen.value = false;
}
</script>

<template>
  <div class="am-admin" :class="{ 'nav-open': menuOpen }">
    <header class="am-topnav">
      <div class="topnav-brand">
        <button
          class="navbar-toggler nav-toggle mobile-only"
          type="button"
          data-bs-toggle="collapse"
          aria-controls="adminMobileNavigation"
          @click="menuOpen = !menuOpen"
          :aria-label="t('common.openMenu')"
          :aria-expanded="menuOpen ? 'true' : 'false'"
        >
          <span class="navbar-toggler-icon" aria-hidden="true"></span>
        </button>
        <RouterLink to="/" class="brand-mark" @click="closeMenus">
          <span class="brand-text">
            <strong>{{ siteName }}</strong>
            <small>{{ t('common.backoffice') }}</small>
          </span>
        </RouterLink>
      </div>

      <GlobalSearch />

      <nav class="topnav-menu" :aria-label="t('common.mainNavigation')">
        <div
          v-for="item in visibleNavigation"
          :key="item.key"
          class="topnav-item"
          :class="{ 'has-submenu': hasChildren(item) }"
        >
          <RouterLink
            :to="parentRoute(item)"
            class="topnav-link"
            :aria-haspopup="hasChildren(item) ? 'true' : undefined"
            @click="closeMenus"
          >
            <span>{{ item.label }}</span>
            <svg v-if="hasChildren(item)" class="topnav-caret" aria-hidden="true" viewBox="0 0 16 16" focusable="false">
              <path fill="currentColor" fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708z" clip-rule="evenodd" />
            </svg>
          </RouterLink>
          <div v-if="hasChildren(item)" class="topnav-submenu card" role="menu">
            <template v-if="item.key === 'modules'">
              <RouterLink
                v-for="child in moduleMenuChildren(item)"
                :key="`${item.key}-${child.route}`"
                class="topnav-submenu-link"
                :to="child.route"
                role="menuitem"
                @click="closeMenus"
              >
                <strong>{{ child.navLabel || child.label }}</strong>
                <small v-if="child.hint">{{ child.hint }}</small>
              </RouterLink>
            </template>
            <template v-else>
              <RouterLink
                v-for="child in visibleChildren(item)"
                :key="`${item.key}-${child.route}`"
                class="topnav-submenu-link"
                :to="child.route"
                role="menuitem"
                @click="closeMenus"
              >
                <strong>{{ child.navLabel || child.label }}</strong>
                <small v-if="child.hint">{{ child.hint }}</small>
              </RouterLink>
            </template>
          </div>
        </div>
      </nav>

      <div class="topnav-actions">
        <label v-if="availableSites.length > 1" class="site-switcher">
          <span>Site</span>
          <select :value="currentSiteId" @change="onSiteChange" aria-label="Changer de site administré">
            <option v-for="site in availableSites" :key="site.id" :value="site.id">
              {{ site.name }}
            </option>
          </select>
        </label>
        <ContentLanguageSwitcher />
        <div class="account-menu">
          <button class="profile-pill" type="button" :title="t('common.userProfile')" :aria-label="t('common.userProfile')" @click="accountMenuOpen = !accountMenuOpen">
            <svg class="profile-pill__icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
              <path d="M3 14s-1 0-1-1 1-4 6-4 6 3 6 4-1 1-1 1H3zm5-6a3 3 0 1 0 0-6 3 3 0 0 0 0 6z" />
            </svg>
          </button>
          <div v-if="accountMenuOpen" class="account-popover card">
            <p class="account-popover__title">Compte</p>
            <RouterLink class="account-option" to="/profile" @click="closeMenus">Mon profil</RouterLink>
            <form class="account-logout-form" method="post" :action="logoutAction">
              <input type="hidden" name="_csrf" :value="context.context?.csrf_token || ''">
              <button class="account-option account-option--danger" type="submit">Déconnexion</button>
            </form>
            <template v-if="isSuperAdmin">
              <hr />
              <p class="account-popover__title">Administration IAM</p>
              <RouterLink class="account-option" to="/iam/users" @click="closeMenus">Utilisateurs / profils</RouterLink>
              <RouterLink v-if="canAdministerGlobalRoles" class="account-option" to="/iam/roles" @click="closeMenus">Rôles / permissions</RouterLink>
            </template>
          </div>
        </div>
      </div>
    </header>

    <div v-if="menuOpen" id="adminMobileNavigation" class="mobile-panel card mobile-only">
      <div v-for="item in visibleNavigation" :key="item.key" class="mobile-nav-group">
        <RouterLink
          :to="parentRoute(item)"
          class="mobile-nav-link mobile-nav-link--parent"
          @click="closeMenus"
        >
          {{ item.label }}
        </RouterLink>
        <div v-if="hasChildren(item)" class="mobile-subnav" :aria-label="`Sous-menu ${item.label}`">
          <RouterLink
            v-for="child in (item.key === 'modules' ? moduleMenuChildren(item) : visibleChildren(item))"
            :key="`${item.key}-${child.route}`"
            :to="child.route"
            class="mobile-nav-link mobile-nav-link--child"
            @click="closeMenus"
          >
            {{ child.navLabel || child.label }}
          </RouterLink>
        </div>
      </div>
    </div>

    <main class="am-main">
      <section class="am-content">
        <div v-if="context.loading && !context.context" class="card muted">{{ t('common.loadingAdminContext') }}</div>
        <p v-else-if="context.error && !context.context" class="alert error">{{ context.error }}</p>
        <slot v-else />
      </section>
    </main>
  </div>
</template>
