<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$runtime = $root . '/backend/bootstrap/runtime.php';
$source = file_get_contents($runtime);
if ($source === false) {
    fwrite(STDERR, "Unable to read runtime bootstrap\n");
    exit(1);
}

if (str_contains($source, 'cms_preload_native_module_providers')) {
    fwrite(STDERR, "Module providers must not be manually preloaded before Composer\n");
    exit(1);
}

require_once $runtime;
require_once $runtime;

$classes = [
    App\Modules\Forms\FormsModuleProvider::class,
    App\Modules\AiAssistant\AiAssistantModuleProvider::class,
];

foreach ($classes as $class) {
    if (class_exists($class, false)) {
        fwrite(STDERR, "Provider was loaded eagerly instead of through the canonical autoloader: {$class}\n");
        exit(1);
    }

    for ($i = 0; $i < 3; $i++) {
        if (!class_exists($class)) {
            fwrite(STDERR, "Provider cannot be loaded: {$class}\n");
            exit(1);
        }
    }

    $provider = new $class();
    if (!$provider instanceof App\Module\ModuleProvider) {
        fwrite(STDERR, "Provider contract mismatch: {$class}\n");
        exit(1);
    }
}

echo "Bootstrap autoload: OK\n";
