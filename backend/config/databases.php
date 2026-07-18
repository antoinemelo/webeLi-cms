<?php

$databaseDirOverride = $_ENV['CMS_DATABASE_DIR'] ?? $_SERVER['CMS_DATABASE_DIR'] ?? getenv('CMS_DATABASE_DIR');
$databaseDir = is_string($databaseDirOverride) && trim($databaseDirOverride) !== ''
    ? rtrim($databaseDirOverride, DIRECTORY_SEPARATOR)
    : base_path('storage/database');

return [
    'core' => ['driver' => 'sqlite', 'path' => $databaseDir . '/core.sqlite'],
    'iam' => ['driver' => 'sqlite', 'path' => $databaseDir . '/iam.sqlite'],
    'forms' => ['driver' => 'sqlite', 'path' => $databaseDir . '/forms.sqlite'],
    'cookies' => ['driver' => 'sqlite', 'path' => $databaseDir . '/cookies.sqlite'],

    // Les modules métier peuvent déclarer leurs propres bases via ModuleProvider::databases().
    // Exemples attendus: crm.sqlite, commerce.sqlite, bookings.sqlite, accounting.sqlite.
    // Le core ne les ouvre pas tant que le module n'est pas installé/activé.
    'modules_dir' => $databaseDir,
];
