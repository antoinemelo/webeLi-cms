<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Services;

use App\Core\Database;
use App\Modules\AiAssistant\Repositories\AiRepositoryBase;

/**
 * Calcule et applique les garde-fous budgétaires du module IA.
 *
 * Les prix sont interprétés par défaut comme un coût par million de tokens,
 * ce qui correspond aux grilles tarifaires les plus courantes des providers IA.
 * Le comportement peut être ajusté par options_json.price_unit :
 * - 1m_tokens|million_tokens|per_million : prix / 1 000 000 tokens ;
 * - 1k_tokens|thousand_tokens|per_thousand : prix / 1 000 tokens ;
 * - token|per_token : prix par token.
 */
final class AiBudgetService extends AiRepositoryBase
{
    public function __construct(?Database $db) { parent::__construct($db); }

    /** @param list<array{role:string,content:string}> $messages */
    public function estimateInputTokens(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $message) {
            $chars += mb_strlen((string) ($message['role'] ?? '')) + mb_strlen((string) ($message['content'] ?? '')) + 8;
        }
        return max(1, (int) ceil($chars / 4));
    }

    public function estimateTokensForText(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }

    /** @param array<string,mixed> $model */
    public function calculateCost(array $model, int $inputTokens, int $outputTokens): float
    {
        $unit = $this->priceUnit($model);
        $divisor = match ($unit) {
            '1k_tokens' => 1000.0,
            'token' => 1.0,
            default => 1000000.0,
        };
        $inputPrice = max(0.0, (float) ($model['input_price'] ?? 0));
        $outputPrice = max(0.0, (float) ($model['output_price'] ?? 0));
        return round(($inputTokens / $divisor) * $inputPrice + ($outputTokens / $divisor) * $outputPrice, 8);
    }

    /** @param array<string,mixed> $model @return array<string,mixed> */
    public function budgetConfig(array $model): array
    {
        $budget = is_array($model['max_monthly_budget'] ?? null) ? $model['max_monthly_budget'] : [];
        $options = is_array($model['options'] ?? null) ? $model['options'] : [];
        $amount = max(0.0, (float) ($budget['amount'] ?? 0));
        $currency = strtoupper(substr((string) ($budget['currency'] ?? $model['currency'] ?? 'CHF'), 0, 3)) ?: 'CHF';
        $mode = strtolower((string) ($budget['mode'] ?? $budget['enforcement'] ?? $options['budget_mode'] ?? 'block'));
        if (!in_array($mode, ['warn', 'block'], true)) {
            $mode = 'block';
        }
        $warningThreshold = (float) ($budget['warning_threshold'] ?? $options['budget_warning_threshold'] ?? 0.8);
        if ($warningThreshold <= 0 || $warningThreshold > 1) {
            $warningThreshold = 0.8;
        }
        return [
            'amount' => $amount,
            'currency' => $currency,
            'mode' => $mode,
            'warning_threshold' => $warningThreshold,
            'price_unit' => $this->priceUnit($model),
        ];
    }

    /** @param array<string,mixed> $model @return array<string,mixed> */
    public function checkBeforeCall(int $siteId, string $usageKey, array $model, int $estimatedInputTokens, int $estimatedOutputTokens): array
    {
        $providerKey = (string) ($model['provider_key'] ?? '');
        if ($providerKey === 'null_provider') {
            return ['allowed' => true, 'status' => 'ok', 'reason' => 'null_provider', 'estimated_cost' => 0.0];
        }

        $budget = $this->budgetConfig($model);
        $estimatedCost = $this->calculateCost($model, $estimatedInputTokens, $estimatedOutputTokens);
        $month = $this->monthlyUsage($siteId, $usageKey, (string) ($model['currency'] ?? $budget['currency']));
        $amount = (float) ($budget['amount'] ?? 0);
        if ($amount <= 0) {
            return [
                'allowed' => true,
                'status' => 'unlimited',
                'budget' => $budget,
                'month' => $month,
                'estimated_cost' => $estimatedCost,
                'projected_cost' => (float) $month['total_cost'] + $estimatedCost,
            ];
        }

        $projected = (float) $month['total_cost'] + $estimatedCost;
        $percentage = $amount > 0 ? $projected / $amount : 0.0;
        $overBudget = $projected > $amount;
        $warning = $percentage >= (float) $budget['warning_threshold'];
        $blocked = $overBudget && ($budget['mode'] ?? 'block') === 'block';

        return [
            'allowed' => !$blocked,
            'status' => $blocked ? 'blocked' : ($overBudget ? 'over_budget_warning' : ($warning ? 'warning' : 'ok')),
            'budget' => $budget,
            'month' => $month,
            'estimated_cost' => $estimatedCost,
            'projected_cost' => $projected,
            'percentage_used_after_estimate' => $amount > 0 ? round($percentage * 100, 2) : 0.0,
            'human_message' => $blocked
                ? 'Le budget IA mensuel de cet usage est dépassé. Aucun appel provider externe n’a été effectué.'
                : ($warning ? 'Le budget IA mensuel approche de sa limite.' : 'Budget IA mensuel disponible.'),
            'suggested_fix' => $blocked
                ? 'Augmentez le budget du modèle, passez le mode budget en avertissement, ou attendez le mois suivant.'
                : ($warning ? 'Surveillez la consommation ou augmentez le budget si l’usage doit continuer.' : ''),
        ];
    }

    /** @param array<string,mixed> $model @param array<string,mixed> $response @return array<string,mixed> */
    public function enrichResponseCost(array $model, array $response, int $fallbackInputTokens = 0): array
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $input = max(0, (int) ($usage['input_tokens'] ?? 0));
        $output = max(0, (int) ($usage['output_tokens'] ?? 0));
        if ($input <= 0) {
            $input = max(0, $fallbackInputTokens);
        }
        if ($output <= 0) {
            $output = $this->estimateTokensForText((string) ($response['content'] ?? $response['response_preview'] ?? ''));
        }
        $usage['input_tokens'] = $input;
        $usage['output_tokens'] = $output;
        $usage['total_tokens'] = max($input + $output, (int) ($usage['total_tokens'] ?? 0));
        $response['usage'] = $usage;
        $response['estimated_cost'] = $this->calculateCost($model, $input, $output);
        $response['currency'] = strtoupper(substr((string) ($model['currency'] ?? 'CHF'), 0, 3)) ?: 'CHF';
        $response['price_unit'] = $this->priceUnit($model);
        return $response;
    }

    /** @return array<string,mixed> */
    public function summary(int $siteId): array
    {
        if (!$this->isAvailable()) {
            return $this->emptySummary($siteId);
        }
        $monthStart = date('Y-m-01 00:00:00');
        $stmt = $this->pdo()->prepare('SELECT * FROM ai_usage_events WHERE created_at >= :month_start ORDER BY created_at DESC, id DESC');
        $stmt->execute(['month_start' => $monthStart]);
        $events = $stmt->fetchAll();
        $total = 0.0;
        $success = 0;
        $failed = 0;
        $inputTokens = 0;
        $outputTokens = 0;
        $byUsage = [];
        $byModel = [];
        $currency = 'CHF';
        foreach ($events as $event) {
            $cost = (float) ($event['estimated_cost'] ?? 0);
            $input = max(0, (int) ($event['input_tokens'] ?? 0));
            $output = max(0, (int) ($event['output_tokens'] ?? 0));
            $details = $this->decodeEventDetails($event);
            if ($input <= 0) {
                $input = $this->estimateInputTokensFromDetails($details);
            }
            if ($output <= 0) {
                $output = $this->estimateOutputTokensFromDetails($details);
            }
            $eventCurrency = strtoupper(substr((string) ($event['currency'] ?? $currency), 0, 3)) ?: $currency;
            $providerKey = (string) ($event['provider_key'] ?? 'unknown_provider');
            $modelKey = (string) ($event['model_key'] ?? 'unknown_model');
            $modelBucketKey = $providerKey . '::' . $modelKey;

            $total += $cost;
            $inputTokens += $input;
            $outputTokens += $output;
            $currency = $eventCurrency;
            $status = (string) ($event['status'] ?? 'success');
            if ($status === 'success') {
                $success++;
            } else {
                $failed++;
            }
            $usageKey = $this->usageKeyFromTaskType((string) ($event['task_type'] ?? 'unknown'));
            $byUsage[$usageKey] ??= ['usage_key' => $usageKey, 'cost' => 0.0, 'calls' => 0, 'success_calls' => 0, 'failed_calls' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'currency' => $eventCurrency];
            $byUsage[$usageKey]['cost'] += $cost;
            $byUsage[$usageKey]['calls']++;
            $byUsage[$usageKey]['input_tokens'] += $input;
            $byUsage[$usageKey]['output_tokens'] += $output;
            if ($status === 'success') {
                $byUsage[$usageKey]['success_calls']++;
            } else {
                $byUsage[$usageKey]['failed_calls']++;
            }

            $byModel[$modelBucketKey] ??= [
                'provider_key' => $providerKey,
                'model_key' => $modelKey,
                'calls' => 0,
                'success_calls' => 0,
                'failed_calls' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'currencies' => [],
            ];
            $byModel[$modelBucketKey]['calls']++;
            $byModel[$modelBucketKey]['input_tokens'] += $input;
            $byModel[$modelBucketKey]['output_tokens'] += $output;
            $byModel[$modelBucketKey]['total_tokens'] += max($input + $output, (int) ($event['total_tokens'] ?? 0));
            if ($status === 'success') {
                $byModel[$modelBucketKey]['success_calls']++;
            } else {
                $byModel[$modelBucketKey]['failed_calls']++;
            }
            $byModel[$modelBucketKey]['currencies'][$eventCurrency] ??= ['currency' => $eventCurrency, 'cost' => 0.0, 'calls' => 0];
            $byModel[$modelBucketKey]['currencies'][$eventCurrency]['cost'] += $cost;
            $byModel[$modelBucketKey]['currencies'][$eventCurrency]['calls']++;
        }

        return [
            'site_id' => $siteId,
            'scope' => 'cms_all_sites',
            'scope_label' => 'Tous les sites et sous-sites du CMS',
            'month' => date('Y-m'),
            'total_cost' => round($total, 8),
            'currency' => $currency,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'successful_calls' => $success,
            'failed_calls' => $failed,
            'calls' => $success + $failed,
            'cost_by_usage' => array_values(array_map(static function (array $row): array {
                $row['cost'] = round((float) $row['cost'], 8);
                return $row;
            }, $byUsage)),
            'spend_by_model' => array_values(array_map(static function (array $row): array {
                $row['currencies'] = array_values(array_map(static function (array $currencyRow): array {
                    $currencyRow['cost'] = round((float) $currencyRow['cost'], 8);
                    return $currencyRow;
                }, $row['currencies'] ?? []));
                return $row;
            }, $byModel)),
        ];
    }

    /** @return array<string,mixed> */
    public function monthlyUsage(int $siteId, string $usageKey, string $currency = 'CHF'): array
    {
        if (!$this->isAvailable()) {
            return ['site_id' => $siteId, 'usage_key' => $usageKey, 'total_cost' => 0.0, 'currency' => $currency, 'calls' => 0];
        }
        $monthStart = date('Y-m-01 00:00:00');
        $stmt = $this->pdo()->prepare('SELECT COALESCE(SUM(estimated_cost), 0) AS cost, COUNT(*) AS calls FROM ai_usage_events WHERE created_at >= :month_start AND (task_type = :exact OR task_type LIKE :suffix)');
        $stmt->execute([
            'month_start' => $monthStart,
            'exact' => $usageKey,
            'suffix' => '%.' . $usageKey,
        ]);
        $row = $stmt->fetch() ?: [];
        return [
            'site_id' => $siteId,
            'scope' => 'cms_all_sites',
            'scope_label' => 'Tous les sites et sous-sites du CMS',
            'usage_key' => $usageKey,
            'month' => date('Y-m'),
            'total_cost' => round((float) ($row['cost'] ?? 0), 8),
            'currency' => strtoupper(substr($currency, 0, 3)) ?: 'CHF',
            'calls' => (int) ($row['calls'] ?? 0),
        ];
    }


    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function decodeEventDetails(array $event): array
    {
        $json = (string) ($event['details_json'] ?? '');
        if (trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $details */
    private function estimateInputTokensFromDetails(array $details): int
    {
        $usage = is_array($details['usage'] ?? null) ? $details['usage'] : [];
        $fromUsage = (int) ($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? 0);
        if ($fromUsage > 0) {
            return $fromUsage;
        }
        $text = trim((string) ($details['system_message'] ?? '')) . "
" . trim((string) ($details['user_message'] ?? $details['prompt'] ?? ''));
        return $this->estimateTokensForText($text);
    }

    /** @param array<string,mixed> $details */
    private function estimateOutputTokensFromDetails(array $details): int
    {
        $usage = is_array($details['usage'] ?? null) ? $details['usage'] : [];
        $fromUsage = (int) ($usage['output_tokens'] ?? $usage['completion_tokens'] ?? 0);
        if ($fromUsage > 0) {
            return $fromUsage;
        }
        return $this->estimateTokensForText((string) ($details['content'] ?? $details['response_preview'] ?? $details['body_preview'] ?? ''));
    }

    /** @param array<string,mixed> $model */
    private function priceUnit(array $model): string
    {
        $options = is_array($model['options'] ?? null) ? $model['options'] : [];
        $unit = strtolower((string) ($model['price_unit'] ?? $options['price_unit'] ?? '1m_tokens'));
        return match ($unit) {
            '1k_tokens', 'thousand_tokens', 'per_thousand', 'per_1k' => '1k_tokens',
            'token', 'per_token' => 'token',
            default => '1m_tokens',
        };
    }

    private function usageKeyFromTaskType(string $taskType): string
    {
        $parts = array_values(array_filter(explode('.', $taskType)));
        return $parts ? (string) end($parts) : 'unknown';
    }

    /** @return array<string,mixed> */
    private function emptySummary(int $siteId): array
    {
        return ['site_id' => $siteId, 'scope' => 'cms_all_sites', 'scope_label' => 'Tous les sites et sous-sites du CMS', 'month' => date('Y-m'), 'total_cost' => 0.0, 'currency' => 'CHF', 'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'successful_calls' => 0, 'failed_calls' => 0, 'calls' => 0, 'cost_by_usage' => [], 'spend_by_model' => []];
    }
}
