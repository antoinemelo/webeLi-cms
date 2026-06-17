<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Services;

/**
 * Rendu volontairement simple et déterministe des templates IA.
 *
 * Syntaxe supportée : {{variable}} avec des clés alphanumériques, underscore et point.
 * Les variables inconnues du template sont ignorées. Les variables manquantes sont
 * signalées et empêchent l'envoi d'un prompt incomplet au provider.
 */
final class AiPromptRenderer
{
    /** @param array<string,mixed> $variables @return array{content:string,used_variables:list<string>,missing_variables:list<string>,unknown_variables:list<string>} */
    public function render(string $template, array $variables): array
    {
        $expected = $this->variablesInTemplate($template);
        $used = [];
        $missing = [];
        $unknown = array_values(array_diff(array_keys($variables), $expected));

        $content = preg_replace_callback('/{{\s*([a-zA-Z0-9_.]+)\s*}}/', function (array $matches) use ($variables, &$used, &$missing): string {
            $key = (string) $matches[1];
            if (!array_key_exists($key, $variables) || $variables[$key] === null || $variables[$key] === '') {
                if (!in_array($key, $missing, true)) {
                    $missing[] = $key;
                }
                return $matches[0];
            }
            if (!in_array($key, $used, true)) {
                $used[] = $key;
            }
            return $this->stringValue($variables[$key]);
        }, $template) ?? $template;

        sort($used);
        sort($missing);
        sort($unknown);

        return [
            'content' => $content,
            'used_variables' => $used,
            'missing_variables' => $missing,
            'unknown_variables' => $unknown,
        ];
    }

    /** @return list<string> */
    private function variablesInTemplate(string $template): array
    {
        preg_match_all('/{{\s*([a-zA-Z0-9_.]+)\s*}}/', $template, $matches);
        $variables = array_values(array_unique(array_map('strval', $matches[1] ?? [])));
        sort($variables);
        return $variables;
    }

    private function stringValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '';
    }
}
