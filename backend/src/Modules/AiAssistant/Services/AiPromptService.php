<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Services;

use App\Core\Database;
use App\Modules\AiAssistant\Repositories\AiRepositoryBase;

final class AiPromptService extends AiRepositoryBase
{
    private readonly AiPromptRenderer $renderer;

    public function __construct(?Database $db, ?AiPromptRenderer $renderer = null)
    {
        parent::__construct($db);
        $this->renderer = $renderer ?? new AiPromptRenderer();
    }

    /** @return list<array<string,mixed>> */
    public function list(?string $category = null, bool $includeTemplates = false): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        $sql = 'SELECT key, name, category, system_prompt, user_template, output_schema_json, language, enabled, version, updated_at FROM ai_prompts';
        $params = [];
        if ($category !== null && $category !== '') {
            $sql .= ' WHERE category = :category';
            $params['category'] = $this->normalizeKey($category);
        }
        $sql .= ' ORDER BY category ASC, key ASC, version DESC';
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(fn(array $row): array => $this->formatPromptRow($row, $includeTemplates), $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function activePrompt(string $actionKey, string $usageKey, ?string $locale = null, ?int $siteId = null): ?array
    {
        unset($siteId); // Réservé pour une future surcharge par site sans changer l'API du service.
        if (!$this->isAvailable()) {
            return null;
        }
        $actionKey = $this->normalizeActionKey($actionKey);
        $usageKey = $this->normalizeKey($usageKey);
        if ($actionKey === '' || $usageKey === '') {
            return null;
        }
        $locales = $this->localeCandidates($locale);
        $stmt = $this->pdo()->prepare(
            'SELECT key, name, category, system_prompt, user_template, output_schema_json, language, enabled, version, updated_at
             FROM ai_prompts
             WHERE key = ?
               AND category = ?
               AND enabled = 1
               AND (language IS NULL OR language IN (' . implode(',', array_fill(0, count($locales), '?')) . '))
             ORDER BY
               CASE
                 WHEN language = ? THEN 0
                 WHEN language IS NULL THEN 2
                 ELSE 1
               END,
               version DESC
             LIMIT 1'
        );
        $params = [$actionKey, $usageKey];
        foreach ($locales as $candidate) {
            $params[] = $candidate;
        }
        $params[] = $locales[0] ?? null;
        $stmt->execute($params);
        $row = $stmt->fetch();
        return is_array($row) ? $this->formatPromptRow($row, true) : null;
    }

    /** @param array<string,mixed> $variables @return array<string,mixed> */
    public function renderPrompt(array $prompt, array $variables): array
    {
        $system = $this->renderer->render((string) ($prompt['system_prompt'] ?? ''), $variables);
        $user = $this->renderer->render((string) ($prompt['user_template'] ?? ''), $variables);
        $used = array_values(array_unique(array_merge($system['used_variables'], $user['used_variables'])));
        $missing = array_values(array_unique(array_merge($system['missing_variables'], $user['missing_variables'])));
        $unknown = array_values(array_unique(array_merge($system['unknown_variables'], $user['unknown_variables'])));
        sort($used);
        sort($missing);
        sort($unknown);

        return [
            'prompt_key' => (string) ($prompt['key'] ?? ''),
            'prompt_name' => (string) ($prompt['name'] ?? ''),
            'system_message' => $system['content'],
            'user_message' => $user['content'],
            'variables_used' => $used,
            'variables_missing' => $missing,
            'variables_unknown' => $unknown,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function formatPromptRow(array $row, bool $includeTemplates): array
    {
        $prompt = [
            'key' => (string) $row['key'],
            'name' => (string) $row['name'],
            'category' => (string) $row['category'],
            'output_schema' => $this->decodeJson((string) $row['output_schema_json'], new \stdClass()),
            'language' => $row['language'],
            'enabled' => (bool) $row['enabled'],
            'version' => (int) $row['version'],
            'updated_at' => (string) $row['updated_at'],
        ];
        if ($includeTemplates) {
            $prompt['system_prompt'] = (string) $row['system_prompt'];
            $prompt['user_template'] = (string) $row['user_template'];
        }
        return $prompt;
    }

    /** @return list<string> */
    private function localeCandidates(?string $locale): array
    {
        $locale = strtolower(trim((string) $locale));
        $locale = preg_replace('/[^a-z0-9_-]+/', '', $locale) ?: '';
        $short = substr(str_replace('_', '-', $locale), 0, 2);
        $items = array_values(array_unique(array_filter([$locale, $short, 'fr'])));
        return $items ?: ['fr'];
    }

    private function normalizeActionKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_.-]+/', '_', $key) ?: '';
        return trim($key, '_.-');
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_]+/', '_', $key) ?: '';
        return trim($key, '_');
    }
}
