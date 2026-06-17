<?php

return [
    // Modules core connus du produit. Ils restent chargés par le noyau éditorial
    // et ne passent pas par le cycle de vie destructif des modules métier.
    'enabled' => [
        'core',
        'pages',
        'articles',
        'seo',
        'taxonomy',
        'media',
        'navigation',
        'workflow',
        'forms',
        // Shell applicatif activé pour publier l'entrée de navigation dans Modules.
        // L'IA elle-même reste désactivée par défaut dans ai.sqlite (ai.enabled = false).
        'ai-assistant',
    ],

    // Providers PHP réellement disponibles. Un provider présent ici devient
    // découvrable par /admin/api/modules, puis installable/activable en base.
    // Il n'est pas nécessaire de modifier le routeur Vue pour chaque module.
    'providers' => [
        App\Modules\Forms\FormsModuleProvider::class,
        App\Modules\AiAssistant\AiAssistantModuleProvider::class,
        // App\Modules\Example\ExampleModuleProvider::class,
    ],

    // Les providers listés ci-dessus ne doivent pas être installés/activés
    // implicitement, sauf si leur key est également présente dans enabled.
    'auto_install_providers' => false,

    // Dossier par défaut pour les bases SQLite dédiées aux modules métier.
    'database_dir' => base_path('storage/database'),
];
