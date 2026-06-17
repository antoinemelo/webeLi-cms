#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/bootstrap/runtime.php';

use App\Core\Database;
use App\Core\Installer;
use App\Core\Logger;
use App\Core\ServiceFactory;
use App\Modules\AiAssistant\Services\AiTaskWorker;

$config = require base_path('backend/bootstrap/config.php');

// Options CLI documentées: --limit=10 --type=ai.test_usage,ai.generate_from_prompt
$options = getopt('', ['limit::', 'type::', 'no-install']);
$limit = max(1, min(100, (int) ($options['limit'] ?? 10)));
$types = [];
if (!empty($options['type'])) {
    $types = array_values(array_filter(array_map('trim', explode(',', (string) $options['type']))));
}

$coreDb = new Database($config['databases']['core']['path']);
$iamDb = new Database($config['databases']['iam']['path']);
$formsDb = isset($config['databases']['forms']['path']) ? new Database($config['databases']['forms']['path']) : null;
$cookiesDb = isset($config['databases']['cookies']['path']) ? new Database($config['databases']['cookies']['path']) : null;
$logger = new Logger(base_path('storage/logs/ai_worker.log'));
$services = new ServiceFactory($coreDb, $iamDb, $formsDb, $cookiesDb, $config, $logger);

if (!isset($options['no-install'])) {
    Installer::ensure($coreDb, $iamDb, $logger, $formsDb, $cookiesDb);
    $services->modules()->syncManifest();
    $services->modules()->applyMigrations();
}

$worker = new AiTaskWorker(
    $services->aiTasks(),
    $services->aiProviderManager(),
    $services->aiUsageLogger(),
    $logger,
);

$result = $worker->run($limit, $types);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(((int) ($result['failed'] ?? 0)) > 0 ? 1 : 0);
