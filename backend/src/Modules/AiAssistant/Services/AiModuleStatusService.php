<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Services;

use App\Module\ModuleRegistry;

final class AiModuleStatusService
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly AiDatabaseConnection $database,
        private readonly AiSettingsService $settings,
        private readonly AiProviderManager $providers,
    ) {}

    /** @return array<string,mixed> */
    public function status(?int $siteId = null): array
    {
        $state = $this->modules->state('ai-assistant');
        $defaultProvider = $this->settings->defaultProvider();
        $defaultModel = $this->settings->defaultChatModel();
        $settings = $this->settings->allSettings();
        return [
            'module' => [
                'key' => 'ai-assistant',
                'installed' => (bool) ($state['is_installed'] ?? false),
                'enabled' => (bool) ($state['is_enabled'] ?? false),
                'configured' => (bool) ($settings['ai.enabled']['value'] ?? false),
            ],
            'database' => $this->database->status(),
            'provider' => $defaultProvider,
            'model' => $defaultModel,
            'runtime_provider' => $this->providers->provider()->status(),
            'scope' => $siteId !== null ? $this->settings->siteSettingFor($siteId) : null,
            'safety' => [
                'external_calls_by_default' => false,
                'direct_cms_table_writes' => false,
                'auto_publish' => false,
                'auto_delete' => false,
                'apply_requires_core_capability' => true,
            ],
        ];
    }
}
