#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../backend/bootstrap/runtime.php';

use App\Core\Database;
use App\Core\Installer;
use App\Core\Logger;
use App\Core\ServiceFactory;
use App\Modules\AiAssistant\Services\AiSuggestionService;

// Options CLI documentées : --days=30 --limit=200 --no-install
$config = require base_path('backend/bootstrap/config.php');
$options = getopt('', ['days::', 'limit::', 'no-install']);
$days = max(1, min(365, (int) ($options['days'] ?? 30)));
$limit = max(1, min(1000, (int) ($options['limit'] ?? 200)));

$coreDb = new Database($config['databases']['core']['path']);
$iamDb = new Database($config['databases']['iam']['path']);
$formsDb = isset($config['databases']['forms']['path']) ? new Database($config['databases']['forms']['path']) : null;
$cookiesDb = isset($config['databases']['cookies']['path']) ? new Database($config['databases']['cookies']['path']) : null;
$logger = new Logger(base_path('storage/logs/ai_suggestions.log'));
$services = new ServiceFactory($coreDb, $iamDb, $formsDb, $cookiesDb, $config, $logger);

if (!isset($options['no-install'])) {
    Installer::ensure($coreDb, $iamDb, $logger, $formsDb, $cookiesDb);
    $services->modules()->syncManifest();
    $services->modules()->applyMigrations();
}

$service = $services->aiSuggestions();
$result = $service->expireOld($days, $limit);
$logger->info('ai_suggestions.expire_old', ['days' => $days, 'limit' => $limit, 'expired' => (int) ($result['expired'] ?? 0)]);

echo json_encode($result + ['days' => $days, 'limit' => $limit], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
