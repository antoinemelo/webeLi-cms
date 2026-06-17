<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Diagnostics;

/**
 * Normalise les erreurs providers IA en diagnostics lisibles pour le backoffice.
 *
 * Cette classe ne journalise pas les secrets. Les détails techniques exposés au
 * frontend sont filtrés et limités afin d'éviter la fuite de clés API, tokens ou
 * headers sensibles.
 */
final class AiErrorDiagnostics
{
    /** @var list<string> */
    public const TYPES = [
        'auth_error',
        'rate_limited',
        'quota_exceeded',
        'timeout',
        'bad_request',
        'provider_unavailable',
        'invalid_response',
        'invalid_json',
        'budget_exceeded',
        'unknown_error',
    ];

    /** @param array<string,mixed> $result */
    public static function enrich(array $result): array
    {
        $success = (bool) ($result['ok'] ?? $result['success'] ?? false);
        $type = $success ? null : self::normalizeType($result['error_type'] ?? null, $result);
        $statusCode = isset($result['status_code']) ? (int) $result['status_code'] : (int) ($result['http_status'] ?? 0);
        $durationMs = (int) ($result['duration_ms'] ?? 0);
        $errorMessage = $success ? null : self::safeMessage($result['error_message'] ?? null, $type, $statusCode);

        $diagnostic = [
            'success' => $success,
            'error_type' => $type,
            'error_message' => $errorMessage,
            'human_message' => $success ? 'Le provider IA a répondu correctement.' : self::humanMessage((string) $type),
            'suggested_fix' => $success ? 'Aucune action corrective nécessaire.' : self::suggestedFix((string) $type),
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
        ];

        return $diagnostic + self::sanitizeResult($result);
    }

    /** @param array<string,mixed> $result */
    public static function normalizeType(mixed $type, array $result = []): string
    {
        $value = is_string($type) ? trim($type) : '';
        if ($value === 'network_error') {
            $value = 'provider_unavailable';
        }
        if (in_array($value, self::TYPES, true)) {
            return $value;
        }

        $statusCode = (int) ($result['status_code'] ?? $result['http_status'] ?? 0);
        if ($statusCode === 401 || $statusCode === 403) {
            return 'auth_error';
        }
        if ($statusCode === 429) {
            $message = strtolower((string) ($result['error_message'] ?? ''));
            return str_contains($message, 'quota') || str_contains($message, 'billing') ? 'quota_exceeded' : 'rate_limited';
        }
        if ($statusCode === 400 || $statusCode === 422) {
            return 'bad_request';
        }
        if ($statusCode >= 500 || $statusCode === 0) {
            return 'provider_unavailable';
        }

        return 'unknown_error';
    }

    public static function humanMessage(string $type): string
    {
        return match ($type) {
            'auth_error' => 'Le provider refuse l’authentification. La clé API, sa référence ou les droits du compte semblent incorrects.',
            'rate_limited' => 'Le provider limite temporairement les requêtes. Le module fonctionne, mais le rythme d’appel est trop élevé.',
            'quota_exceeded' => 'Le quota ou le crédit du compte provider semble épuisé.',
            'timeout' => 'Le provider n’a pas répondu dans le délai configuré.',
            'bad_request' => 'La requête envoyée au provider est invalide ou incompatible avec le modèle sélectionné.',
            'provider_unavailable' => 'Le provider ou son endpoint est indisponible, inaccessible ou a retourné une erreur serveur.',
            'invalid_response' => 'Le provider a répondu, mais la réponse ne contient pas de contenu IA exploitable.',
            'invalid_json' => 'Le provider a retourné une réponse qui n’est pas un JSON valide.',
            'budget_exceeded' => 'Le budget IA mensuel configuré pour cet usage est dépassé ou serait dépassé par cet appel.',
            default => 'Le test IA a échoué sans diagnostic provider suffisamment précis.',
        };
    }

    public static function suggestedFix(string $type): string
    {
        return match ($type) {
            'auth_error' => 'Vérifier api_key_ref, la variable d’environnement serveur, les droits de la clé et redémarrer PHP-FPM/opcache si nécessaire.',
            'rate_limited' => 'Réessayer plus tard, réduire les tests simultanés ou augmenter les limites côté provider.',
            'quota_exceeded' => 'Vérifier la facturation, les crédits, le quota mensuel et le projet associé dans la console du provider.',
            'timeout' => 'Vérifier la connectivité serveur, l’URL de base, la disponibilité du provider et augmenter le timeout seulement si nécessaire.',
            'bad_request' => 'Contrôler base_url, model_key, compatibilité /chat/completions ou /models, et les paramètres max_tokens/temperature.',
            'provider_unavailable' => 'Vérifier l’état du provider, l’URL configurée, le DNS, le proxy éventuel et les logs serveur.',
            'invalid_response' => 'Contrôler le modèle choisi et le format de réponse attendu ; tester aussi l’endpoint dans la console provider.',
            'invalid_json' => 'Vérifier que l’endpoint retourne bien application/json et que base_url pointe vers une API compatible OpenAI.',
            'budget_exceeded' => 'Augmenter le budget du modèle, passer le mode budget en avertissement, choisir un modèle moins coûteux ou attendre le mois suivant.',
            default => 'Consulter les détails techniques repliables, puis vérifier la configuration du fournisseur et du modèle.',
        };
    }

    /** @param array<string,mixed> $result */
    public static function sanitizeResult(array $result): array
    {
        $safe = [];
        foreach ($result as $key => $value) {
            if (self::isSensitiveKey((string) $key)) {
                $safe[$key] = '[redacted]';
                continue;
            }
            if ($key === 'body') {
                $safe['body_preview'] = self::sanitizeString(mb_substr((string) $value, 0, 500));
                continue;
            }
            if ($key === 'json') {
                $safe[$key] = self::sanitizeValue($value);
                continue;
            }
            $safe[$key] = self::sanitizeValue($value);
        }
        return $safe;
    }

    private static function safeMessage(mixed $message, ?string $type, int $statusCode): string
    {
        $text = is_string($message) && trim($message) !== ''
            ? trim($message)
            : 'Erreur IA ' . ($type ?: 'unknown_error') . ($statusCode > 0 ? ' (HTTP ' . $statusCode . ')' : '');
        return self::sanitizeString(mb_substr($text, 0, 500));
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        // Les compteurs de tokens sont des métriques de consommation, pas des secrets.
        // Les redacter casse la persistance des coûts car le contrôleur journalise
        // les diagnostics enrichis dans ai_usage_events.
        if (in_array($normalized, [
            'input_tokens',
            'output_tokens',
            'total_tokens',
            'prompt_tokens',
            'completion_tokens',
            'cached_tokens',
            'max_tokens',
        ], true)) {
            return false;
        }

        return str_contains($normalized, 'authorization')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'password')
            || $normalized === 'token'
            || $normalized === 'access_token'
            || $normalized === 'refresh_token'
            || $normalized === 'api_key'
            || $normalized === 'apikey'
            || $normalized === 'x-api-key';
    }

    private static function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $safe[$key] = self::isSensitiveKey((string) $key) ? '[redacted]' : self::sanitizeValue($item);
            }
            return $safe;
        }
        if (is_string($value)) {
            return self::sanitizeString($value);
        }
        return $value;
    }

    private static function sanitizeString(string $value): string
    {
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._\-+=\/]+/i', 'Bearer [redacted]', $value) ?? $value;
        $value = preg_replace('/(api[_-]?key|token|secret|authorization)(["\'\s:=]+)([^"\'\s,;}]+)/i', '$1$2[redacted]', $value) ?? $value;
        return $value;
    }
}
