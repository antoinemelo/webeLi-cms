export type ContextualHelpId =
  | 'docs.index'
  | 'settings.configuration'
  | 'system.blueprints'
  | 'tools.maintenance'
  | 'modules.sale'
  | 'modules.commerce'
  | 'business.crm';

export type ContextualHelpEntry = {
  id: ContextualHelpId;
  label: string;
  summary: string;
  commonErrors: string[];
  docId: string;
};

export const contextualHelp: Record<ContextualHelpId, ContextualHelpEntry> = {
  'docs.index': {
    id: 'docs.index',
    label: 'Aide Docs',
    summary: 'Trouver la bonne source documentaire et filtrer par profil.',
    commonErrors: ['Chercher une règle de permission dans la documentation.', 'Modifier une page générée manuellement.'],
    docId: 'reference~contextual-help'
  },
  'settings.configuration': {
    id: 'settings.configuration',
    label: 'Aide Configuration',
    summary: 'Régler sites, langues, apparence, SEO, médias et paramètres système.',
    commonErrors: ['Modifier le mauvais site actif.', 'Confondre langue de contenu et langue d’interface.'],
    docId: 'administration~index'
  },
  'system.blueprints': {
    id: 'system.blueprints',
    label: 'Aide Structures',
    summary: 'Maintenir les structures éditoriales, champs et groupes réutilisables.',
    commonErrors: ['Supprimer un champ système.', 'Activer un brouillon sans relire les versions.'],
    docId: 'administration~content-model~blueprints'
  },
  'tools.maintenance': {
    id: 'tools.maintenance',
    label: 'Aide Maintenance',
    summary: 'Lire versions, bases, dépendances, cache, recherche et journaux.',
    commonErrors: ['Confondre inventaire informatif et mise à jour.', 'Vider le cache pour corriger une migration manquante.'],
    docId: 'administration~maintenance'
  },
  'modules.sale': {
    id: 'modules.sale',
    label: 'Aide Vente',
    summary: 'Contrôler commandes, POS, paiements et stock transactionnel.',
    commonErrors: ['Créer une vente sans canal actif.', 'Interpréter un paiement en attente comme payé.'],
    docId: 'business~vente'
  },
  'modules.commerce': {
    id: 'modules.commerce',
    label: 'Aide E-Commerce',
    summary: 'Comprendre la configuration des sites e-commerce rattachée aux réglages Ventes.',
    commonErrors: ['Supposer qu’ouvrir les réglages publie un Shop.', 'Confondre canal de vente existant et Shop activé.'],
    docId: 'business~commerce'
  },
  'business.crm': {
    id: 'business.crm',
    label: 'Aide CRM',
    summary: 'Gérer contacts, sociétés, mémos, consentements et messages.',
    commonErrors: ['Travailler sur une relation archivée.', 'Oublier les consentements avant un message.'],
    docId: 'business~crm-guide-utilisateur'
  }
};

export function contextualHelpEntry(id: ContextualHelpId): ContextualHelpEntry {
  return contextualHelp[id];
}
