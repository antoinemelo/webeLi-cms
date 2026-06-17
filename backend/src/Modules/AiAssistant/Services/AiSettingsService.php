<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Services;

use App\Core\Database;
use App\Modules\AiAssistant\Repositories\AiRepositoryBase;

final class AiSettingsService extends AiRepositoryBase
{
    public function __construct(?Database $db) { parent::__construct($db); }

    /** @return array<string,mixed> */
    public function allSettings(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $settings = [];
        foreach ($this->pdo()->query('SELECT key, value_json, description, enabled FROM ai_settings ORDER BY key')->fetchAll() as $row) {
            $settings[(string) $row['key']] = [
                'value' => $this->decodeJson((string) $row['value_json'], null),
                'description' => (string) $row['description'],
                'enabled' => (bool) $row['enabled'],
            ];
        }
        return $settings;
    }

    /** @return list<array<string,mixed>> */
    public function providerTypes(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        return array_map(fn(array $row): array => [
            'id' => (int) $row['id'],
            'key' => (string) $row['key'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'sort_order' => (int) $row['sort_order'],
            'enabled' => (bool) $row['enabled'],
            'is_system' => (bool) $row['is_system'],
        ], $this->pdo()->query('SELECT * FROM ai_provider_types ORDER BY sort_order ASC, name ASC')->fetchAll());
    }

    /** @return array<string,mixed> */
    public function saveProviderType(array $payload): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Base IA indisponible.');
        }
        $key = $this->normalizeKey((string) ($payload['key'] ?? ''));
        if ($key === '') {
            throw new \InvalidArgumentException('La clé du type d’usage IA est obligatoire.');
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Le nom du type d’usage IA est obligatoire.');
        }
        $this->pdo()->prepare(
            'INSERT INTO ai_provider_types(key, name, description, sort_order, enabled, is_system, created_at, updated_at)
             VALUES(:key, :name, :description, :sort_order, :enabled, :is_system, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON CONFLICT(key) DO UPDATE SET
                name = excluded.name,
                description = excluded.description,
                sort_order = excluded.sort_order,
                enabled = excluded.enabled,
                updated_at = CURRENT_TIMESTAMP'
        )->execute([
            'key' => $key,
            'name' => $name,
            'description' => trim((string) ($payload['description'] ?? '')),
            'sort_order' => max(0, (int) ($payload['sort_order'] ?? 100)),
            'enabled' => !empty($payload['enabled']) ? 1 : 0,
            'is_system' => !empty($payload['is_system']) ? 1 : 0,
        ]);
        return $this->providerTypeByKey($key) ?? [];
    }

    /** @return array<string,mixed> */
    public function deleteProviderType(string $key): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Base IA indisponible.');
        }
        $key = $this->normalizeKey($key);
        $type = $this->providerTypeByKey($key);
        if (!$type) {
            throw new \RuntimeException('Type d’usage IA introuvable.');
        }
        if (!empty($type['is_system'])) {
            throw new \InvalidArgumentException('Ce type système est protégé. Désactivez-le plutôt que le supprimer.');
        }
        foreach (['ai_providers' => 'type_key', 'ai_model_usages' => 'usage_key', 'ai_site_usage_settings' => 'usage_key'] as $table => $column) {
            $stmt = $this->pdo()->prepare("SELECT COUNT(*) AS n FROM {$table} WHERE {$column} = :key");
            $stmt->execute(['key' => $key]);
            if ((int) ($stmt->fetch()['n'] ?? 0) > 0) {
                throw new \InvalidArgumentException('Ce type est utilisé par la configuration IA.');
            }
        }
        $this->pdo()->prepare('DELETE FROM ai_provider_types WHERE key = :key')->execute(['key' => $key]);
        return ['provider_types' => $this->providerTypes()];
    }

    /** @return list<array<string,mixed>> */
    public function providers(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $sql = 'SELECT p.*, t.name AS type_name
                FROM ai_providers p
                LEFT JOIN ai_provider_types t ON t.key = p.type_key
                ORDER BY p.is_default DESC, t.sort_order ASC, p.name ASC';
        return array_map(fn(array $row): array => [
            'id' => (int) $row['id'],
            'type_key' => (string) ($row['type_key'] ?? 'editorial'),
            'type_name' => (string) ($row['type_name'] ?? ''),
            'key' => (string) $row['key'],
            'name' => (string) $row['name'],
            'provider_type' => (string) $row['provider_type'],
            'base_url' => $row['base_url'],
            'api_key_ref' => $row['api_key_ref'],
            'enabled' => (bool) $row['enabled'],
            'is_default' => (bool) $row['is_default'],
            'options' => $this->decodeJson((string) $row['options_json'], []),
        ], $this->pdo()->query($sql)->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    public function providerSiteScopes(?int $siteId = null): array
    {
        // Compatibilité API ancienne : le périmètre fournisseur est désormais déduit de ai_site_usage_settings.
        return [];
    }

    /** @param list<string> $providerKeys @return list<array<string,mixed>> */
    public function saveProviderSiteScopes(int $siteId, array $providerKeys): array
    {
        return [];
    }

    /** @return list<array<string,mixed>> */
    public function models(?string $providerKey = null): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $sql = 'SELECT m.*, p.key AS provider_key, p.name AS provider_name, p.type_key, p.provider_type, p.base_url, p.api_key_ref AS provider_api_key_ref, p.enabled AS provider_enabled
                FROM ai_models m
                JOIN ai_providers p ON p.id = m.provider_id';
        $params = [];
        if ($providerKey !== null && $providerKey !== '') {
            $sql .= ' WHERE p.key = :provider_key';
            $params['provider_key'] = $providerKey;
        }
        $sql .= ' ORDER BY m.name ASC, p.name ASC, m.key ASC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $usageMap = $this->modelUsagesMap(array_map(fn(array $row): int => (int) $row['id'], $rows));
        return array_map(function (array $row) use ($usageMap): array {
            $usageKeys = $usageMap[(int) $row['id']]['keys'] ?? [];
            $usageNames = $usageMap[(int) $row['id']]['names'] ?? [];
            if (!$usageKeys) {
                $usageKeys = [$this->fallbackUsageKeyForModelType((string) ($row['model_type'] ?? ''), (string) ($row['type_key'] ?? 'editorial'))];
                $usageNames = array_values(array_filter([$this->providerTypeByKey($usageKeys[0])['name'] ?? $usageKeys[0]]));
            }
            return [
                'id' => (int) $row['id'],
                'provider_id' => (int) $row['provider_id'],
                'provider_key' => (string) $row['provider_key'],
                'provider_name' => (string) $row['provider_name'],
                'type_key' => (string) $row['type_key'],
                'usage_key' => $usageKeys[0] ?? (string) ($row['type_key'] ?? 'editorial'),
                'usage_keys' => $usageKeys,
                'usage_names' => $usageNames,
                'usage_name' => implode(', ', $usageNames),
                'key' => (string) $row['key'],
                'name' => (string) $row['name'],
                'model_type' => (string) $row['model_type'],
                'context_window' => (int) $row['context_window'],
                'input_price' => (float) $row['input_price'],
                'output_price' => (float) $row['output_price'],
                'currency' => (string) $row['currency'],
                'price_unit' => $this->priceUnitFromOptions($this->decodeJson((string) $row['options_json'], [])),
                'api_key_ref' => $row['api_key_ref'],
                'effective_api_key_ref' => $row['api_key_ref'] ?: $row['provider_api_key_ref'],
                'max_monthly_budget' => $this->normalizeModelBudget((string) $row['max_monthly_budget_json'], (string) $row['currency']),
                'enabled' => (bool) $row['enabled'],
                'provider_enabled' => (bool) $row['provider_enabled'],
                'provider_type' => (string) $row['provider_type'],
                'base_url' => $row['base_url'],
                'options' => $this->decodeJson((string) $row['options_json'], []),
            ];
        }, $rows);
    }


    /** @return array{amount:float|int,currency:string,mode:string,warning_threshold:float} */
    private function normalizeModelBudget(string $json, string $modelCurrency): array
    {
        $budget = $this->decodeJson($json, ['amount' => 0]);
        $currency = strtoupper(substr($modelCurrency, 0, 3)) ?: 'CHF';
        $mode = strtolower((string) ($budget['mode'] ?? $budget['enforcement'] ?? 'block'));
        if (!in_array($mode, ['warn', 'block'], true)) {
            $mode = 'block';
        }
        $warningThreshold = (float) ($budget['warning_threshold'] ?? 0.8);
        if ($warningThreshold <= 0 || $warningThreshold > 1) {
            $warningThreshold = 0.8;
        }
        return [
            'amount' => max(0, (float) ($budget['amount'] ?? 0)),
            'currency' => $currency,
            'mode' => $mode,
            'warning_threshold' => $warningThreshold,
        ];
    }

    private function priceUnitFromOptions(array $options): string
    {
        $unit = strtolower((string) ($options['price_unit'] ?? '1m_tokens'));
        return match ($unit) {
            '1k_tokens', 'thousand_tokens', 'per_thousand', 'per_1k' => '1k_tokens',
            'token', 'per_token' => 'token',
            default => '1m_tokens',
        };
    }

    /** @param array<string,mixed> $settings */
    public function updateSettings(array $settings): void
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Base IA indisponible.');
        }
        $allowed = ['ai.enabled', 'ai.default_model_id', 'ai.default_text_model', 'ai.default_embedding_model', 'ai.log_prompts', 'ai.log_responses'];
        foreach ($settings as $key => $value) {
            if (!in_array((string) $key, $allowed, true)) {
                continue;
            }
            $this->pdo()->prepare('UPDATE ai_settings SET value_json = :value_json, updated_at = CURRENT_TIMESTAMP WHERE key = :key')
                ->execute(['key' => (string) $key, 'value_json' => $this->encodeJson($value)]);
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveProvider(array $payload): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Base IA indisponible.');
        }
        $key = $this->normalizeKey((string) ($payload['key'] ?? ''));
        if ($key === '') {
            throw new \InvalidArgumentException('La clé du fournisseur IA est obligatoire.');
        }
        $typeKey = $this->normalizeKey((string) ($payload['type_key'] ?? 'editorial')) ?: 'editorial';
        if (!$this->providerTypeByKey($typeKey)) {
            throw new \InvalidArgumentException('Type d’usage IA introuvable.');
        }
        $adapter = (string) ($payload['provider_type'] ?? 'openai_compatible');
        if (!in_array($adapter, ['null', 'openai_compatible', 'infomaniak', 'local', 'custom_http', 'ollama'], true)) {
            throw new \InvalidArgumentException('Adaptateur technique IA non supporté.');
        }
        $apiKeyRef = $this->normalizeApiKeyRef((string) ($payload['api_key_ref'] ?? ''));
        $isDefault = !empty($payload['is_default']);
        $this->pdo()->beginTransaction();
        try {
            if ($isDefault) {
                $this->pdo()->exec('UPDATE ai_providers SET is_default = 0');
            }
            $this->pdo()->prepare(
                'INSERT INTO ai_providers(type_key, key, name, provider_type, base_url, api_key_ref, enabled, is_default, options_json, created_at, updated_at)
                 VALUES(:type_key, :key, :name, :provider_type, :base_url, :api_key_ref, :enabled, :is_default, :options_json, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                 ON CONFLICT(key) DO UPDATE SET
                    type_key = excluded.type_key,
                    name = excluded.name,
                    provider_type = excluded.provider_type,
                    base_url = excluded.base_url,
                    api_key_ref = excluded.api_key_ref,
                    enabled = excluded.enabled,
                    is_default = excluded.is_default,
                    options_json = excluded.options_json,
                    updated_at = CURRENT_TIMESTAMP'
            )->execute([
                'type_key' => $typeKey,
                'key' => $key,
                'name' => trim((string) ($payload['name'] ?? '')) ?: $key,
                'provider_type' => $adapter,
                'base_url' => trim((string) ($payload['base_url'] ?? '')) ?: null,
                'api_key_ref' => $apiKeyRef,
                'enabled' => !empty($payload['enabled']) ? 1 : 0,
                'is_default' => $isDefault ? 1 : 0,
                'options_json' => $this->encodeJson(is_array($payload['options'] ?? null) ? $payload['options'] : []),
            ]);
            $this->pdo()->commit();
        } catch (\Throwable $e) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $e;
        }
        return $this->providerByKey($key) ?? [];
    }

    /** @return array<string,mixed> */
    public function deleteProvider(string $key): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Base IA indisponible.');
        }
        $key = $this->normalizeKey($key);
        if ($key === '' || $key === 'null_provider') {
            throw new \InvalidArgumentException('Le provider nul est protégé et ne peut pas être supprimé.');
        }
        if (!$this->providerByKey($key)) {
            throw new \RuntimeException('Fournisseur IA introuvable.');
        }
        $this->pdo()->beginTransaction();
        try {
            $this->pdo()->prepare('DELETE FROM ai_providers WHERE key = :key')->execute(['key' => $key]);
            $this->pdo()->prepare('UPDATE ai_site_usage_settings SET provider_key = NULL, model_key = NULL, model_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE provider_key = :key')->execute(['key' => $key]);
            $hasDefault = (int) ($this->pdo()->query('SELECT COUNT(*) AS n FROM ai_providers WHERE is_default = 1')->fetch()['n'] ?? 0);
            if ($hasDefault < 1) {
                $this->pdo()->exec("UPDATE ai_providers SET is_default = 1, enabled = 1, updated_at = CURRENT_TIMESTAMP WHERE key = 'null_provider'");
            }
            $this->pdo()->commit();
        } catch (\Throwable $e) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $e;
        }
        return ['providers' => $this->providers(), 'models' => $this->models()];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveModel(array $payload): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Base IA indisponible.');
        }
        $providerKey = $this->normalizeKey((string) ($payload['provider_key'] ?? ''));
        $provider = $this->providerByKey($providerKey);
        if (!$provider) {
            throw new \InvalidArgumentException('Fournisseur IA introuvable pour ce modèle.');
        }
        $usageKeys = $this->normalizeUsageKeys($payload['usage_keys'] ?? $payload['usage_key'] ?? ['editorial']);
        if (!$usageKeys) {
            throw new \InvalidArgumentException('Sélectionnez au moins un usage compatible pour ce modèle.');
        }
        foreach ($usageKeys as $usageKey) {
            if (!$this->providerTypeByKey($usageKey)) {
                throw new \InvalidArgumentException('Type d’usage IA introuvable pour ce modèle.');
            }
        }
        $key = strtolower(trim((string) ($payload['key'] ?? '')));
        if ($key === '' || !preg_match('/^[a-z0-9_.:-]+$/', $key)) {
            throw new \InvalidArgumentException('Clé de modèle IA invalide.');
        }
        // Le type technique reste interne. Il est déduit des usages compatibles
        // uniquement pour orienter l'adaptateur runtime, pas pour contraindre l'UX.
        $type = $this->technicalTypeForUsages($usageKeys);
        $budget = is_array($payload['max_monthly_budget'] ?? null) ? $payload['max_monthly_budget'] : ['amount' => 0];
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
        $priceUnit = $this->priceUnitFromOptions(['price_unit' => (string) ($payload['price_unit'] ?? $options['price_unit'] ?? '1m_tokens')]);
        $budgetMode = strtolower((string) ($budget['mode'] ?? $budget['enforcement'] ?? $payload['budget_mode'] ?? $options['budget_mode'] ?? 'block'));
        if (!in_array($budgetMode, ['warn', 'block'], true)) {
            $budgetMode = 'block';
        }
        $options['price_unit'] = $priceUnit;
        $options['budget_mode'] = $budgetMode;
        $modelCurrency = strtoupper(substr((string) ($payload['currency'] ?? 'CHF'), 0, 3)) ?: 'CHF';
        $this->pdo()->beginTransaction();
        try {
            $this->pdo()->prepare(
                'INSERT INTO ai_models(provider_id, key, name, model_type, context_window, input_price, output_price, currency, api_key_ref, max_monthly_budget_json, enabled, options_json, created_at, updated_at)
                 VALUES(:provider_id, :key, :name, :model_type, :context_window, :input_price, :output_price, :currency, :api_key_ref, :budget, :enabled, :options_json, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                 ON CONFLICT(provider_id, key) DO UPDATE SET
                    name = excluded.name,
                    model_type = excluded.model_type,
                    context_window = excluded.context_window,
                    input_price = excluded.input_price,
                    output_price = excluded.output_price,
                    currency = excluded.currency,
                    api_key_ref = excluded.api_key_ref,
                    max_monthly_budget_json = excluded.max_monthly_budget_json,
                    enabled = excluded.enabled,
                    options_json = excluded.options_json,
                    updated_at = CURRENT_TIMESTAMP'
            )->execute([
                'provider_id' => (int) $provider['id'],
                'key' => $key,
                'name' => trim((string) ($payload['name'] ?? '')) ?: $key,
                'model_type' => $type,
                'context_window' => max(0, (int) ($payload['context_window'] ?? 0)),
                'input_price' => max(0, (float) ($payload['input_price'] ?? 0)),
                'output_price' => max(0, (float) ($payload['output_price'] ?? 0)),
                'currency' => $modelCurrency,
                'api_key_ref' => $this->normalizeApiKeyRef((string) ($payload['api_key_ref'] ?? '')),
                'budget' => $this->encodeJson([
                    'amount' => max(0, (float) ($budget['amount'] ?? 0)),
                    'currency' => $modelCurrency,
                    'mode' => $budgetMode,
                    'warning_threshold' => 0.8,
                ]),
                'enabled' => !empty($payload['enabled']) ? 1 : 0,
                'options_json' => $this->encodeJson($options),
            ]);
            $stmt = $this->pdo()->prepare('SELECT id FROM ai_models WHERE provider_id = :provider_id AND key = :key LIMIT 1');
            $stmt->execute(['provider_id' => (int) $provider['id'], 'key' => $key]);
            $modelId = (int) ($stmt->fetch()['id'] ?? 0);
            if ($modelId <= 0) {
                throw new \RuntimeException('Modèle IA impossible à résoudre après enregistrement.');
            }
            $this->pdo()->prepare('DELETE FROM ai_model_usages WHERE model_id = :model_id')->execute(['model_id' => $modelId]);
            $stmt = $this->pdo()->prepare('INSERT INTO ai_model_usages(model_id, usage_key, sort_order) VALUES(:model_id, :usage_key, :sort_order)');
            foreach (array_values($usageKeys) as $index => $usageKey) {
                $stmt->execute(['model_id' => $modelId, 'usage_key' => $usageKey, 'sort_order' => ($index + 1) * 10]);
            }
            $this->pdo()->commit();
        } catch (\Throwable $e) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $e;
        }
        foreach ($this->models($providerKey) as $model) {
            if ($model['key'] === $key) {
                return $model;
            }
        }
        return [];
    }

    /** @return array<string,mixed> */
    public function deleteModel(string $providerKey, string $modelKey): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Base IA indisponible.');
        }
        $providerKey = $this->normalizeKey($providerKey);
        $modelKey = strtolower(trim($modelKey));
        if ($providerKey === 'null_provider' && $modelKey === 'null_text_model') {
            throw new \InvalidArgumentException('Le modèle nul est protégé et ne peut pas être supprimé.');
        }
        $provider = $this->providerByKey($providerKey);
        if (!$provider) {
            throw new \RuntimeException('Fournisseur IA introuvable pour ce modèle.');
        }
        $this->pdo()->prepare('DELETE FROM ai_models WHERE provider_id = :provider_id AND key = :key')->execute(['provider_id' => (int) $provider['id'], 'key' => $modelKey]);
        $this->pdo()->prepare('UPDATE ai_site_usage_settings SET model_id = NULL, model_key = NULL, updated_at = CURRENT_TIMESTAMP WHERE model_key = :model_key AND (provider_key IS NULL OR provider_key = :provider_key)')->execute(['model_key' => $modelKey, 'provider_key' => $providerKey]);
        return ['providers' => $this->providers(), 'models' => $this->models()];
    }

    /** @return list<array<string,mixed>> */
    public function siteSettings(?int $siteId = null): array
    {
        return $this->siteUsageSettings($siteId);
    }

    /** @return list<array<string,mixed>> */
    public function siteUsageSettings(?int $siteId = null): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $sql = 'SELECT s.*, t.name AS usage_name, t.sort_order, m.key AS resolved_model_key, m.name AS resolved_model_name, p.key AS resolved_provider_key, p.name AS resolved_provider_name
                FROM ai_site_usage_settings s
                JOIN ai_provider_types t ON t.key = s.usage_key
                LEFT JOIN ai_models m ON m.id = s.model_id
                LEFT JOIN ai_providers p ON p.id = m.provider_id';
        $params = [];
        if ($siteId !== null && $siteId > 0) {
            $sql .= ' WHERE s.site_id = :site_id';
            $params['site_id'] = $siteId;
        }
        $sql .= ' ORDER BY s.site_id ASC, t.sort_order ASC, t.name ASC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn(array $row): array => $this->siteUsageSettingsRow($row), $stmt->fetchAll());
    }

    /** @return array<string,mixed> */
    public function siteSettingFor(int $siteId): array
    {
        $items = $this->siteUsageSettings($siteId);
        foreach ($items as $item) {
            if (($item['usage_key'] ?? '') === 'editorial') {
                return $item;
            }
        }
        return $this->defaultUsageSetting($siteId, 'editorial');
    }

    /** @return array<string,mixed> */
    public function siteUsageSettingFor(int $siteId, string $usageKey): array
    {
        $usageKey = $this->normalizeKey($usageKey) ?: 'editorial';
        foreach ($this->siteUsageSettings($siteId) as $item) {
            if (($item['usage_key'] ?? '') === $usageKey) {
                return $item;
            }
        }
        return $this->defaultUsageSetting($siteId, $usageKey);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveSiteSetting(array $payload): array
    {
        return $this->saveSiteUsageSetting($payload);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveSiteUsageSetting(array $payload): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Base IA indisponible.');
        }
        $siteId = (int) ($payload['site_id'] ?? 0);
        if ($siteId <= 0) {
            throw new \InvalidArgumentException('Site obligatoire pour la configuration IA.');
        }
        $usageKey = $this->normalizeKey((string) ($payload['usage_key'] ?? 'editorial')) ?: 'editorial';
        if (!$this->providerTypeByKey($usageKey)) {
            throw new \InvalidArgumentException('Type d’usage IA introuvable.');
        }
        $mode = (string) ($payload['mode'] ?? ($usageKey === 'editorial' ? 'enabled' : 'disabled'));
        if (!in_array($mode, ['inherit','enabled','disabled'], true)) {
            throw new \InvalidArgumentException('Mode IA invalide.');
        }

        // En mode désactivé ou héritage, le choix éventuel d'un ancien modèle
        // ne doit jamais bloquer l'enregistrement. Cela permet notamment de
        // désactiver proprement un usage même si le modèle ou le fournisseur
        // précédemment sélectionné a été désactivé dans le catalogue.
        $modelId = $mode === 'enabled' ? (int) ($payload['model_id'] ?? 0) : 0;
        $model = $modelId > 0 ? $this->modelById($modelId) : null;
        if ($mode === 'enabled' && !$model) {
            throw new \InvalidArgumentException('Choisissez un modèle IA actif pour cet usage.');
        }
        if ($mode === 'enabled' && $model && (!$model['enabled'] || !$model['provider_enabled'])) {
            throw new \InvalidArgumentException('Le modèle et son fournisseur doivent être actifs.');
        }
        if ($mode === 'enabled' && $model && !in_array($usageKey, $model['usage_keys'] ?? [], true)) {
            throw new \InvalidArgumentException('Le modèle choisi n’est pas compatible avec cet usage IA.');
        }
        $this->pdo()->prepare(
            'INSERT INTO ai_site_usage_settings(site_id, usage_key, mode, model_id, provider_key, model_key, translation_enabled, seo_enabled, notes, created_at, updated_at)
             VALUES(:site_id, :usage_key, :mode, :model_id, :provider_key, :model_key, :translation_enabled, :seo_enabled, :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON CONFLICT(site_id, usage_key) DO UPDATE SET
                mode = excluded.mode,
                model_id = excluded.model_id,
                provider_key = excluded.provider_key,
                model_key = excluded.model_key,
                translation_enabled = excluded.translation_enabled,
                seo_enabled = excluded.seo_enabled,
                notes = excluded.notes,
                updated_at = CURRENT_TIMESTAMP'
        )->execute([
            'site_id' => $siteId,
            'usage_key' => $usageKey,
            'mode' => $mode,
            'model_id' => $model ? (int) $model['id'] : null,
            'provider_key' => $model ? (string) $model['provider_key'] : null,
            'model_key' => $model ? (string) $model['key'] : null,
            'translation_enabled' => $usageKey === 'editorial' && !empty($payload['translation_enabled']) ? 1 : 0,
            'seo_enabled' => $usageKey === 'editorial' && !empty($payload['seo_enabled']) ? 1 : 0,
            'notes' => trim((string) ($payload['notes'] ?? '')),
        ]);
        return $this->siteUsageSettingFor($siteId, $usageKey);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function siteUsageSettingsRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'site_id' => (int) $row['site_id'],
            'usage_key' => (string) $row['usage_key'],
            'usage_name' => (string) ($row['usage_name'] ?? ''),
            'mode' => (string) $row['mode'],
            'model_id' => $row['model_id'] !== null ? (int) $row['model_id'] : null,
            'provider_key' => $row['provider_key'] !== null ? (string) $row['provider_key'] : null,
            'model_key' => $row['model_key'] !== null ? (string) $row['model_key'] : null,
            'resolved_provider_key' => $row['resolved_provider_key'] !== null ? (string) $row['resolved_provider_key'] : null,
            'resolved_provider_name' => $row['resolved_provider_name'] !== null ? (string) $row['resolved_provider_name'] : null,
            'resolved_model_key' => $row['resolved_model_key'] !== null ? (string) $row['resolved_model_key'] : null,
            'resolved_model_name' => $row['resolved_model_name'] !== null ? (string) $row['resolved_model_name'] : null,
            'translation_enabled' => (bool) $row['translation_enabled'],
            'seo_enabled' => (bool) $row['seo_enabled'],
            'notes' => (string) $row['notes'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @return array<string,mixed> */
    private function defaultUsageSetting(int $siteId, string $usageKey): array
    {
        return [
            'id' => null,
            'site_id' => $siteId,
            'usage_key' => $usageKey,
            'usage_name' => $this->providerTypeByKey($usageKey)['name'] ?? $usageKey,
            'mode' => $usageKey === 'editorial' ? 'enabled' : 'disabled',
            'model_id' => null,
            'provider_key' => null,
            'model_key' => null,
            'resolved_provider_key' => null,
            'resolved_provider_name' => null,
            'resolved_model_key' => null,
            'resolved_model_name' => null,
            'translation_enabled' => false,
            'seo_enabled' => false,
            'notes' => '',
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /** @return array<string,mixed>|null */
    public function providerByKey(string $key): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT * FROM ai_providers WHERE key = :key LIMIT 1');
        $stmt->execute(['key' => $this->normalizeKey($key)]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'type_key' => (string) ($row['type_key'] ?? 'editorial'),
            'key' => (string) $row['key'],
            'name' => (string) $row['name'],
            'provider_type' => (string) $row['provider_type'],
            'base_url' => $row['base_url'],
            'api_key_ref' => $row['api_key_ref'],
            'enabled' => (bool) $row['enabled'],
            'is_default' => (bool) $row['is_default'],
            'options' => $this->decodeJson((string) $row['options_json'], []),
        ];
    }

    /** @return array<string,mixed>|null */
    /** @return array<string,mixed>|null */
    public function usageTypeForKey(string $key): ?array
    {
        return $this->providerTypeByKey($key);
    }

    private function providerTypeByKey(string $key): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM ai_provider_types WHERE key = :key LIMIT 1');
        $stmt->execute(['key' => $this->normalizeKey($key)]);
        $row = $stmt->fetch();
        return is_array($row) ? [
            'id' => (int) $row['id'],
            'key' => (string) $row['key'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'sort_order' => (int) $row['sort_order'],
            'enabled' => (bool) $row['enabled'],
            'is_system' => (bool) $row['is_system'],
        ] : null;
    }

    /** @return array<string,mixed>|null */
    public function modelById(int $id): ?array
    {
        foreach ($this->models() as $model) {
            if ((int) $model['id'] === $id) {
                return $model;
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public function modelForSiteUsage(int $siteId, string $usageKey = 'editorial'): ?array
    {
        $setting = $this->siteUsageSettingFor($siteId, $usageKey);
        $isExplicitSetting = ($setting['id'] ?? null) !== null;
        if (($setting['mode'] ?? 'disabled') !== 'enabled') {
            return $isExplicitSetting ? null : $this->firstActiveModelForUsage($usageKey);
        }
        $modelId = (int) (($setting['model_id'] ?? 0) ?: 0);
        if ($modelId <= 0) {
            return $this->firstActiveModelForUsage($usageKey);
        }
        $model = $this->modelById($modelId);
        if (!$model || empty($model['enabled']) || empty($model['provider_enabled'])) {
            return null;
        }
        if (!in_array($usageKey, $model['usage_keys'] ?? [], true)) {
            return null;
        }
        return $model;
    }

    /** @return array<string,mixed>|null */
    private function firstActiveModelForUsage(string $usageKey): ?array
    {
        $usageKey = $this->normalizeKey($usageKey);
        foreach ($this->models() as $model) {
            if (!empty($model['enabled']) && !empty($model['provider_enabled']) && in_array($usageKey, $model['usage_keys'] ?? [], true)) {
                return $model;
            }
        }
        return null;
    }


    /** @param list<int> $modelIds @return array<int,array{keys:list<string>,names:list<string>}> */
    private function modelUsagesMap(array $modelIds): array
    {
        if (!$modelIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($modelIds), '?'));
        $stmt = $this->pdo()->prepare("SELECT mu.model_id, mu.usage_key, t.name FROM ai_model_usages mu JOIN ai_provider_types t ON t.key = mu.usage_key WHERE mu.model_id IN ({$placeholders}) ORDER BY t.name ASC");
        $stmt->execute($modelIds);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['model_id'];
            $map[$id] ??= ['keys' => [], 'names' => []];
            $map[$id]['keys'][] = (string) $row['usage_key'];
            $map[$id]['names'][] = (string) $row['name'];
        }
        return $map;
    }

    /** @param mixed $value @return list<string> */
    private function normalizeUsageKeys(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $keys = [];
        foreach ($items as $item) {
            $key = $this->normalizeKey((string) $item);
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /** @param list<string> $usageKeys */
    private function technicalTypeForUsages(array $usageKeys): string
    {
        $keys = array_values(array_unique(array_filter($usageKeys, fn(string $key): bool => $key !== '')));
        $chatLike = ['editorial', 'automation', 'development'];
        foreach ($chatLike as $usageKey) {
            if (in_array($usageKey, $keys, true)) {
                return 'chat';
            }
        }
        foreach (['embeddings', 'reranking', 'transcription', 'voice', 'image', 'local'] as $usageKey) {
            if (in_array($usageKey, $keys, true)) {
                return $this->technicalTypeForUsage($usageKey);
            }
        }
        return 'chat';
    }

    private function technicalTypeForUsage(string $usageKey): string
    {
        return match ($usageKey) {
            'embeddings' => 'embedding',
            'reranking' => 'reranker',
            'transcription' => 'speech_to_text',
            'voice' => 'audio',
            'image' => 'image',
            'local' => 'local',
            default => 'chat',
        };
    }

    private function fallbackUsageKeyForModelType(string $modelType, string $providerTypeKey): string
    {
        return match ($modelType) {
            'embedding' => 'embeddings',
            'reranker' => 'reranking',
            'speech_to_text' => 'transcription',
            'audio' => 'voice',
            'image' => 'image',
            'local' => 'local',
            default => $this->normalizeKey($providerTypeKey) ?: 'editorial',
        };
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?: '';
        return trim($key, '_');
    }

    private function normalizeApiKeyRef(string $apiKeyRef): ?string
    {
        $apiKeyRef = trim($apiKeyRef);
        if ($apiKeyRef === '') {
            return null;
        }
        if (!str_starts_with($apiKeyRef, 'env:')) {
            $apiKeyRef = 'env:' . $apiKeyRef;
        }
        if (!preg_match('/^env:[A-Z][A-Z0-9_]{2,}$/', $apiKeyRef)) {
            throw new \InvalidArgumentException('La clé API doit être référencée par une variable d’environnement, par exemple env:OPENAI_API_KEY.');
        }
        return $apiKeyRef;
    }

    /** @return array<string,mixed>|null */
    public function defaultProvider(): ?array
    {
        foreach ($this->providers() as $provider) {
            if ((bool) ($provider['is_default'] ?? false)) {
                return $provider;
            }
        }
        return $this->providers()[0] ?? null;
    }

    /** @return array<string,mixed>|null */
    public function defaultChatModel(): ?array
    {
        $provider = $this->defaultProvider();
        foreach ($this->models((string) (($provider['key'] ?? '') ?: '')) as $model) {
            if (in_array(($model['model_type'] ?? ''), ['chat', 'code'], true) && (bool) ($model['enabled'] ?? false)) {
                return $model;
            }
        }
        return null;
    }
}
