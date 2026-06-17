<?php

return [
    'core' => ['driver' => 'sqlite', 'path' => base_path('storage/database/core.sqlite')],
    'iam' => ['driver' => 'sqlite', 'path' => base_path('storage/database/iam.sqlite')],
    'forms' => ['driver' => 'sqlite', 'path' => base_path('storage/database/forms.sqlite')],
    'cookies' => ['driver' => 'sqlite', 'path' => base_path('storage/database/cookies.sqlite')],

    // Les modules métier peuvent déclarer leurs propres bases via ModuleProvider::databases().
    // Exemples attendus: crm.sqlite, commerce.sqlite, bookings.sqlite, accounting.sqlite.
    // Le core ne les ouvre pas tant que le module n'est pas installé/activé.
    'modules_dir' => base_path('storage/database'),
];
