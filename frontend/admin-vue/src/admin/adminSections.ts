import { dashboardLinks, studioLinks, moduleWorkbenchLinks, type SectionLink } from '@/router/navigation';

export type AdminSectionKey = 'dashboard' | 'studio' | 'modules';

export type AdminSectionBlock = {
  key: string;
  title: string;
  description?: string;
  type: 'quick_actions' | 'studio_group';
  items: SectionLink[];
};

export type AdminSection = {
  key: AdminSectionKey;
  title: string;
  eyebrow: string;
  intro: string;
  blocks: AdminSectionBlock[];
};

export const adminSections: Record<AdminSectionKey, AdminSection> = {
  dashboard: {
    key: 'dashboard',
    eyebrow: 'Pilotage',
    title: 'Cockpit',
    intro: 'Une vue courte pour vérifier le site principal, suivre les contenus récents, gérer les classifications éditoriales et traiter les points SEO prioritaires.',
    blocks: [
      {
        key: 'quick-start',
        title: 'Pilotage éditorial',
        description: 'Accès directs aux contrôles transversaux : audit SEO, taxonomies et configuration du site.',
        type: 'quick_actions',
        items: dashboardLinks
      }
    ]
  },
  studio: {
    key: 'studio',
    eyebrow: 'Studio éditorial',
    title: 'Contenus et menus',
    intro: 'Le cœur de production du CMS : créer, structurer et préparer les contenus publiables sans écrans fantômes.',
    blocks: [
      {
        key: 'studio-core',
        title: 'Production éditoriale',
        description: 'Pages, articles et menus regroupés dans un seul espace de production cohérent.',
        type: 'studio_group',
        items: studioLinks
      }
    ]
  },
  modules: {
    key: 'modules',
    eyebrow: 'Modules',
    title: 'Extensions métier',
    intro: 'Installer, activer et diagnostiquer les modules sans mélanger leurs données avec le noyau éditorial.',
    blocks: [
      {
        key: 'module-workbench',
        title: 'Modules',
        description: 'Accès aux modèles métier déclarés par les modules. La gouvernance des modules est disponible en ouvrant directement l’espace Modules.',
        type: 'quick_actions',
        items: moduleWorkbenchLinks
      }
    ]
  }
};
