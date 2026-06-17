<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Services;

/**
 * Registre applicatif des actions IA contextuelles.
 *
 * Il ne remplace pas les blueprints : il décrit les boutons/actions que les
 * écrans éditoriaux pourront afficher. L’exécution réelle reste contrôlée par
 * les permissions, la configuration d’usage, les budgets et le cycle de vie
 * des suggestions IA.
 */
final class AiActionRegistry
{
    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly ?AiBudgetService $budgets = null,
    ) {}

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_values($this->definitions());
    }

    /**
     * @param list<string> $userPermissions
     * @return list<array<string,mixed>>
     */
    public function availableFor(int $siteId, ?string $contentType = null, array $userPermissions = []): array
    {
        $contentType = $this->normalizeContext($contentType ?: 'content');
        return array_values(array_map(
            fn(array $action): array => $this->enrich($action, $siteId, $contentType, $userPermissions),
            array_filter($this->all(), fn(array $action): bool => $this->matchesContentType($action, $contentType)),
        ));
    }

    /** @return array<string,mixed>|null */
    public function get(string $actionKey): ?array
    {
        $key = $this->normalizeActionKey($actionKey);
        return $this->definitions()[$key] ?? null;
    }

    /** @return array<string,array<string,mixed>> */
    private function definitions(): array
    {
        return [
            'seo.meta_description.suggest' => $this->action(
                'seo.meta_description.suggest',
                'Suggérer une méta-description',
                'Prépare une méta-description courte à partir du titre et du contenu courant.',
                'ai.seo.suggest',
                'editorial',
                'seo.meta_description',
                ['seo', 'content', 'content_entry'],
                ['title' => 'string', 'text' => 'string', 'locale' => 'string'],
                ['meta_description' => 'string'],
                true,
                false,
            ),
            'seo.title.suggest' => $this->action(
                'seo.title.suggest',
                'Suggérer un titre SEO',
                'Propose un titre SEO lisible sans remplacer automatiquement le titre existant.',
                'ai.seo.suggest',
                'editorial',
                'seo.title',
                ['seo', 'content', 'content_entry'],
                ['title' => 'string', 'text' => 'string', 'locale' => 'string'],
                ['title' => 'string'],
                true,
                false,
            ),
            'editorial.rewrite' => $this->action(
                'editorial.rewrite',
                'Réécrire le texte',
                'Réécrit un champ éditorial sélectionné en conservant le sens.',
                'ai.content.suggest',
                'editorial',
                'editorial.rewrite',
                ['content', 'content_entry', 'block', 'field'],
                ['text' => 'string', 'locale' => 'string', 'tone' => 'string?'],
                ['text' => 'string'],
                true,
                false,
            ),
            'translation.draft' => $this->action(
                'translation.draft',
                'Préparer une traduction',
                'Prépare un brouillon de traduction pour une langue cible.',
                'ai.translation.suggest',
                'editorial',
                'translation.draft',
                ['translation', 'content', 'content_entry'],
                ['source_text' => 'string', 'source_locale' => 'string?', 'target_locale' => 'string'],
                ['draft' => 'string'],
                false,
                true,
            ),
            'media.alt_text.suggest' => $this->action(
                'media.alt_text.suggest',
                'Suggérer un texte alternatif',
                'Rédige une proposition de texte alternatif factuel pour un média.',
                'ai.content.suggest',
                'image',
                'media.alt_text',
                ['media', 'image', 'asset'],
                ['image_context' => 'string', 'locale' => 'string'],
                ['alt_text' => 'string'],
                true,
                false,
            ),
        ];
    }

    /** @param list<string> $contexts @param array<string,string> $inputSchema @param array<string,string> $outputSchema @return array<string,mixed> */
    private function action(string $key, string $label, string $description, string $permission, string $usageKey, string $promptKey, array $contexts, array $inputSchema, array $outputSchema, bool $supportsStreaming, bool $supportsAsync): array
    {
        return [
            'action_key' => $key,
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'required_permission' => $permission,
            'usage_key' => $usageKey,
            'prompt_key' => $promptKey,
            'input_schema' => $this->schema($inputSchema),
            'output_schema' => $this->schema($outputSchema),
            'supports_streaming' => $supportsStreaming,
            'supports_async' => $supportsAsync,
            'contexts' => $contexts,
            'result_policy' => 'suggestion_only',
            'apply_policy' => 'never_direct_apply',
            'suggestion_type' => $key,
        ];
    }

    /** @param array<string,string> $fields @return array<string,mixed> */
    private function schema(array $fields): array
    {
        $properties = [];
        $required = [];
        foreach ($fields as $key => $type) {
            $optional = str_ends_with($type, '?');
            $cleanType = rtrim($type, '?') ?: 'string';
            $properties[$key] = ['type' => $cleanType];
            if (!$optional) {
                $required[] = $key;
            }
        }
        return ['type' => 'object', 'properties' => $properties, 'required' => $required];
    }

    /** @param list<string> $userPermissions @return array<string,mixed> */
    private function enrich(array $action, int $siteId, string $contentType, array $userPermissions): array
    {
        $usageKey = (string) $action['usage_key'];
        $requiredPermission = (string) $action['required_permission'];
        $model = $this->settings->modelForSiteUsage($siteId, $usageKey);
        $setting = $this->settings->siteUsageSettingFor($siteId, $usageKey);
        $hasPermission = in_array($requiredPermission, $userPermissions, true);
        $usageActive = (($setting['mode'] ?? 'disabled') === 'enabled') || (($setting['id'] ?? null) === null && $model !== null);
        $budget = $model && $this->budgets ? $this->budgets->summary($siteId) : null;
        $availability = [];
        if (!$hasPermission) {
            $availability[] = 'permission_missing';
        }
        if (!$usageActive || !$model) {
            $availability[] = 'usage_not_configured';
        }
        if ($model && empty($model['provider_enabled'])) {
            $availability[] = 'provider_disabled';
        }
        if ($model && empty($model['enabled'])) {
            $availability[] = 'model_disabled';
        }
        return $action + [
            'site_id' => $siteId,
            'content_type' => $contentType,
            'available' => $availability === [],
            'availability' => $availability,
            'usage_configured' => $model !== null,
            'usage_enabled' => $usageActive,
            'provider_key' => $model['provider_key'] ?? null,
            'provider_label' => $model['provider_name'] ?? null,
            'model_key' => $model['key'] ?? null,
            'model_label' => $model['name'] ?? null,
            'budget_status' => is_array($budget) ? [
                'month' => $budget['month'] ?? null,
                'total_cost' => $budget['total_cost'] ?? 0,
                'currency' => $budget['currency'] ?? 'CHF',
                'note' => 'Le budget est recontrôlé juste avant chaque exécution réelle.',
            ] : null,
            'execution_preconditions' => [
                'permission' => $requiredPermission,
                'usage_enabled' => true,
                'budget_check' => true,
                'output_becomes_ai_suggestion' => true,
            ],
        ];
    }

    private function matchesContentType(array $action, string $contentType): bool
    {
        $contexts = array_map('strval', $action['contexts'] ?? []);
        return in_array($contentType, $contexts, true) || in_array('content', $contexts, true) && in_array($contentType, ['content_entry', 'page', 'article', 'content'], true);
    }

    private function normalizeContext(string $context): string
    {
        $context = strtolower(trim($context));
        $context = preg_replace('/[^a-z0-9_.-]+/', '_', $context) ?: 'content';
        return trim($context, '_.-') ?: 'content';
    }

    private function normalizeActionKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_.-]+/', '_', $key) ?: '';
        return trim($key, '_.-');
    }
}
