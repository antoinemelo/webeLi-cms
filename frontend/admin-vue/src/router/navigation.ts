export type PermissionKey = string;

export type SectionLink = {
  label: string;
  route: string;
  permission?: PermissionKey;
  anyPermission?: PermissionKey[];
  hint?: string;
  disabled?: boolean;
  navLabel?: string;
};

export type MainNavigationItem = {
  key: 'dashboard' | 'studio' | 'modules' | 'assets';
  label: string;
  route: string;
  permission?: PermissionKey;
  anyPermission?: PermissionKey[];
  children?: SectionLink[];
};

export const contentLinks: SectionLink[] = [
  { label: 'Pages', route: '/contents/pages', permission: 'content.read', hint: 'Arborescence, URL, brouillons, preview et publication.' },
  { label: 'Articles', route: '/contents/articles', permission: 'content.read', hint: 'Actualités, billets, statuts et SEO éditorial.' },
  { label: 'Imports / Exports', route: '/imports-exports', anyPermission: ['imports_exports.read', 'imports_exports.write', 'imports_exports.manage'], hint: 'Exporter des contenus publiés et préparer les futurs imports éditoriaux.' },
  { label: 'Archives de pages', route: '/contents/page_archives', permission: 'content.archive', hint: 'Pages spécialisées qui listent ou redirigent des contenus de type page.' },
  { label: 'Archives d’articles', route: '/contents/article_archives', permission: 'content.archive', hint: 'Pages spécialisées pour listes, archives et agrégations d’articles.' }
];

export const structureLinks: SectionLink[] = [
  { label: 'Menus', route: '/menus', permission: 'menu.read', hint: 'Navigation publique et emplacements.' },
  { label: 'Taxonomies', route: '/taxonomies', permission: 'taxonomy.read', hint: 'Catégories, tags et vocabulaires.' },
  { label: 'Structures de contenu', route: '/blueprints', permission: 'blueprints.read', hint: 'Structures éditoriales, champs, onglets et groupes réutilisables.' },
  { label: 'Structures des modules', navLabel: 'Structures des modules', route: '/blueprints?group=modules', permission: 'blueprints.read', hint: 'Ressources métier, schémas headless et structures déclarées par les modules.' },
  { label: 'Cookies', route: '/cookies', permission: 'cookies.read', hint: 'Consentements, services tiers, bannière et scripts conditionnels.' }
];

export const studioLinks: SectionLink[] = [
  ...contentLinks,
  ...structureLinks.filter((link) => ['/menus'].includes(link.route))
];


export const moduleWorkbenchLinks: SectionLink[] = [];

export const iamLinks: SectionLink[] = [
  { label: 'Utilisateurs', route: '/iam/users', permission: 'users.read', hint: 'Comptes, statuts, accès par site et réinitialisation de mot de passe.' },
  { label: 'Rôles et permissions', route: '/iam/roles', permission: 'roles.read', hint: 'RBAC natif : rôles, permissions et matrice de droits.' },
  { label: 'Sessions', route: '/iam/sessions', permission: 'sessions.read', hint: 'Sessions actives, expiration et révocation.' },
  { label: 'Journal d’audit', route: '/iam/audit', permission: 'audit.read', hint: 'Actions sensibles journalisées.' }
];

export const dashboardStructureLinks: SectionLink[] = structureLinks.filter((link) => ['/taxonomies', '/blueprints', '/cookies'].includes(link.route));

export const dashboardLinks: SectionLink[] = [
  { label: 'Audit SEO', navLabel: 'Audit', route: '/seo/audit', permission: 'content.read', hint: 'Identifier les problèmes visibles avant publication.' },
  ...dashboardStructureLinks,
  { label: 'Configuration', route: '/settings', permission: 'settings.read', hint: 'Ajuster les paramètres du site et du backoffice.' },
  { label: 'Maintenance', route: '/maintenance', permission: 'maintenance.manage', hint: 'Cache, recherche, logs et audits.' }
];

export const assetLinks: SectionLink[] = [
  { label: 'Médias', route: '/media', permission: 'media.read', hint: 'Images, documents, fichiers et métadonnées média.' },
  { label: 'Docs', route: '/docs', hint: 'Toute la documentation du CMS.' }
];

export const mainNavigation: MainNavigationItem[] = [
  { key: 'dashboard', label: 'Cockpit', route: '/', children: dashboardLinks },
  { key: 'studio', label: 'Studio', route: '/studio', permission: 'content.read', children: studioLinks },
  { key: 'modules', label: 'Modules', route: '/modules', anyPermission: ['modules.read', 'modules.manage', 'blueprints.read', 'forms.read', 'forms.manage'], children: moduleWorkbenchLinks },
  { key: 'assets', label: 'Actifs', route: '/media', children: assetLinks }
];

export type AdminSearchAction = SectionLink & {
  key: string;
  section: string;
  keywords: string[];
};

function searchAction(key: string, section: string, link: SectionLink, keywords: string[] = []): AdminSearchAction {
  return { key, section, keywords: [link.label, link.hint ?? '', link.navLabel ?? '', ...keywords], ...link };
}

export const adminSearchActions: AdminSearchAction[] = [
  searchAction('dashboard.open', 'Navigation', { label: 'Cockpit', route: '/' }, ['accueil', 'dashboard', 'tableau de bord']),
  searchAction('studio.open', 'Navigation', { label: 'Studio', route: '/studio', permission: 'content.read', hint: 'Ouvrir le studio éditorial.' }, ['contenu', 'édition']),
  searchAction('static.exports.open', 'Production éditoriale', { label: 'Imports / Exports', route: '/imports-exports', anyPermission: ['imports_exports.read', 'imports_exports.write', 'imports_exports.manage'], hint: 'Exporter une page, une langue ou un site en HTML statique.' }, ['export', 'statique', 'release']),
  ...contentLinks.flatMap((link) => {
    const archive = link.route.includes('archive');
    const plural = link.route.includes('article') ? 'articles' : 'pages';
    const singular = plural === 'articles' ? 'article' : 'page';
    return [
      searchAction(`${archive ? 'archives.' : ''}${plural}.list`, 'Contenus', link, [singular, plural, archive ? 'archive' : 'liste']),
      searchAction(`${archive ? 'archives.' : ''}${plural}.create`, 'Créer', { label: archive ? `Créer une archive de ${plural}` : `Créer ${singular === 'article' ? 'un article' : 'une page'}`, route: `${link.route}/new`, permission: archive ? 'content.archive' : 'content.create', hint: archive ? `Ajouter une archive de ${plural}.` : `Ajouter ${singular === 'article' ? 'un article' : 'une page'}.` }, ['créer', 'ajouter', 'nouveau', singular, plural, archive ? 'archive' : ''])
    ];
  }),
  searchAction('media.open', 'Actifs', { label: 'Bibliothèque médias', route: '/media', permission: 'media.read', hint: 'Images, documents et fichiers.' }, ['image', 'fichier', 'upload', 'actifs']),
  searchAction('docs.open', 'Actifs', { label: 'Documentation', route: '/docs', hint: 'Toute la documentation du CMS.' }, ['docs', 'documentation', 'guide', 'aide']),
  ...moduleWorkbenchLinks.map((link) => searchAction(`modules.${link.route.replace(/^\//, '').replace(/\//g, '.')}`, 'Modules', link, ['module', 'extension', 'métier', 'blueprint'])),
  ...structureLinks.map((link) => searchAction(`structure.${link.route.replace(/^\//, '').replace(/\//g, '.')}`, 'Structure', link, ['navigation', 'classement'])),
  searchAction('seo.audit', 'Qualité', { label: 'Audit SEO & IA', navLabel: 'Audit', route: '/seo/audit', permission: 'content.read', hint: 'Scores, problèmes et recommandations.' }, ['seo', 'ia', 'score', 'audit', 'recommandations']),
  searchAction('settings.open', 'Système', { label: 'Configuration', route: '/settings', permission: 'settings.read', hint: 'Réglages du site.' }, ['paramètres', 'système']),
  searchAction('cookies.open', 'Système', { label: 'Cookies et consentements', navLabel: 'Cookies', route: '/cookies', permission: 'cookies.read', hint: 'Bannière RGPD, catégories, services et scripts.' }, ['cookies', 'rgpd', 'consentement', 'eprivacy']),
  ...iamLinks.map((link) => searchAction(`iam.${link.route.replace(/^\//, '').replace(/\//g, '.')}`, 'IAM', link, ['rbac', 'sécurité', 'utilisateur', 'rôle'])),
  searchAction('profile.open', 'Compte', { label: 'Profil utilisateur', route: '/profile', hint: 'Compte et préférences.' }, ['compte', 'utilisateur'])
];

export function canShow(permission: PermissionKey | undefined, can: (permission: string) => boolean, anyPermission: PermissionKey[] = []): boolean {
  if (permission && can(permission)) return true;
  if (anyPermission.length > 0) return anyPermission.some((candidate) => can(candidate));
  return !permission;
}

export function visibleSectionLinks(items: SectionLink[] | undefined, can: (permission: string) => boolean): SectionLink[] {
  return (items ?? []).filter((entry) => canShow(entry.permission, can, entry.anyPermission));
}

export type DynamicModuleNavigationEntry = SectionLink & {
  key?: string;
  section?: string;
  module_key?: string;
  source?: string;
  sort_order?: number;
};

export function normalizeModuleNavigation(entries: DynamicModuleNavigationEntry[] | undefined): DynamicModuleNavigationEntry[] {
  return (entries ?? [])
    .filter((entry) => typeof entry.label === 'string' && typeof entry.route === 'string' && entry.label.trim() !== '' && entry.route.startsWith('/'))
    .map((entry, index) => ({
      ...entry,
      key: entry.key ?? `module.${entry.module_key ?? 'unknown'}.${index}`,
      source: entry.source ?? 'module',
      sort_order: typeof entry.sort_order === 'number' ? entry.sort_order : 500 + index
    }))
    .sort((a, b) => (a.sort_order ?? 500) - (b.sort_order ?? 500));
}

export function withModuleNavigation(staticItems: SectionLink[], moduleItems: DynamicModuleNavigationEntry[] | undefined, can: (permission: string) => boolean): SectionLink[] {
  const visibleModules = normalizeModuleNavigation(moduleItems).filter((entry) => canShow(entry.permission, can, entry.anyPermission));
  const knownRoutes = new Set(staticItems.map((item) => item.route));
  return [
    ...staticItems,
    ...visibleModules.filter((item) => !knownRoutes.has(item.route))
  ];
}

export function moduleSearchActions(moduleItems: DynamicModuleNavigationEntry[] | undefined): AdminSearchAction[] {
  return normalizeModuleNavigation(moduleItems).map((link) => searchAction(
    link.key ?? `module.${link.module_key ?? 'unknown'}.${link.route.replace(/^\//, '').replace(/\//g, '.')}`,
    link.section ?? 'Modules',
    link,
    ['module', link.module_key ?? '']
  ));
}
