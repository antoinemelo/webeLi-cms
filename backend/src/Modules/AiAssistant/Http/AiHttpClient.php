<?php

declare(strict_types=1);

namespace App\Modules\AiAssistant\Http;

/**
 * Client HTTP centralisé pour les appels providers IA.
 *
 * Objectif : conserver une dépendance PHP native (cURL), éviter les appels
 * file_get_contents/stream_context_create et retourner une structure stable
 * utilisable par les providers, les tests et les journaux d'usage.
 */
final class AiHttpClient
{
    /** @param array<string,string> $headers @param array<string,mixed>|null $payload */
    public function requestJson(string $method, string $url, array $headers = [], ?array $payload = null, int $timeoutSeconds = 15): array
    {
        $started = microtime(true);
        $method = strtoupper(trim($method));
        $timeoutSeconds = max(1, $timeoutSeconds);

        if (!extension_loaded('curl')) {
            return $this->failure($started, 0, '', null, 'provider_unavailable', 'Extension PHP cURL non disponible.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return $this->failure($started, 0, '', null, 'provider_unavailable', 'Initialisation cURL impossible.');
        }

        $body = null;
        $headerLines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if ($name !== '' && $value !== '') {
                $headerLines[] = $name . ': ' . $value;
            }
        }

        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($body)) {
                curl_close($curl);
                return $this->failure($started, 0, '', null, 'bad_request', 'Payload JSON invalide.');
            }
            $headerLines[] = 'Content-Type: application/json';
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($curl);
        $curlErrno = curl_errno($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($curlErrno !== 0) {
            $type = $curlErrno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'provider_unavailable';
            return $this->failure($started, $statusCode, '', null, $type, $curlError !== '' ? $curlError : 'Erreur réseau cURL.');
        }

        $rawBody = is_string($responseBody) ? $responseBody : '';
        $json = null;
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json = is_array($decoded) ? $decoded : null;
            } elseif ($statusCode >= 200 && $statusCode < 300) {
                return $this->failure($started, $statusCode, $rawBody, null, 'invalid_json', json_last_error_msg());
            }
        }

        $errorType = $this->classifyError($statusCode, $json, $rawBody);
        $ok = $statusCode >= 200 && $statusCode < 300 && $errorType === null;

        return [
            'ok' => $ok,
            'status_code' => $statusCode,
            'body' => $rawBody,
            'json' => $json,
            'duration_ms' => $this->durationMs($started),
            'error_type' => $errorType,
            'error_message' => $ok ? null : $this->errorMessage($json, $rawBody, $statusCode, $errorType),
            'provider_response_id' => $this->providerResponseId($json),
        ];
    }


    /**
     * Appel JSON streamé compatible SSE provider OpenAI.
     *
     * @param array<string,string> $headers
     * @param array<string,mixed> $payload
     * @param callable(string,array<string,mixed>):void $onEvent
     * @return array<string,mixed>
     */
    public function streamJson(string $method, string $url, array $headers, array $payload, callable $onEvent, int $timeoutSeconds = 60): array
    {
        $started = microtime(true);
        $method = strtoupper(trim($method));
        $timeoutSeconds = max(1, $timeoutSeconds);
        $content = '';
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
        $providerResponseId = null;
        $lastError = null;
        $buffer = '';
        $statusCode = 0;
        $eventStarted = false;

        if (!extension_loaded('curl')) {
            return $this->failure($started, 0, '', null, 'provider_unavailable', 'Extension PHP cURL non disponible.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            return $this->failure($started, 0, '', null, 'provider_unavailable', 'Initialisation cURL impossible.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            curl_close($curl);
            return $this->failure($started, 0, '', null, 'bad_request', 'Payload JSON invalide.');
        }

        $headerLines = ['Accept: text/event-stream', 'Content-Type: application/json'];
        foreach ($headers as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if ($name !== '' && $value !== '') {
                $headerLines[] = $name . ': ' . $value;
            }
        }

        $handleData = function (string $data) use (&$content, &$usage, &$providerResponseId, &$lastError, &$eventStarted, $onEvent): void {
            $data = trim($data);
            if ($data === '' || $data === '[DONE]') {
                return;
            }
            $decoded = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $lastError = ['type' => 'invalid_json', 'message' => json_last_error_msg(), 'body' => mb_substr($data, 0, 500)];
                $onEvent('error', ['error_type' => 'invalid_json', 'error_message' => json_last_error_msg()]);
                return;
            }

            if (!$eventStarted) {
                $eventStarted = true;
                $providerResponseId = $this->providerResponseId($decoded);
                $onEvent('start', ['provider_response_id' => $providerResponseId]);
            }

            if (isset($decoded['error']) && is_array($decoded['error'])) {
                $message = $this->errorMessage($decoded, '', 0, 'provider_unavailable') ?? 'Erreur provider pendant le streaming.';
                $lastError = ['type' => $this->classifyError(0, $decoded, ''), 'message' => $message];
                $onEvent('error', ['error_type' => $lastError['type'], 'error_message' => $message]);
                return;
            }

            $delta = $decoded['choices'][0]['delta']['content'] ?? $decoded['choices'][0]['text'] ?? null;
            if (is_string($delta) && $delta !== '') {
                $content .= $delta;
                $onEvent('token', ['text' => $delta]);
            }

            $rawUsage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
            if ($rawUsage !== []) {
                $usage = [
                    'input_tokens' => (int) ($rawUsage['prompt_tokens'] ?? $rawUsage['input_tokens'] ?? $usage['input_tokens'] ?? 0),
                    'output_tokens' => (int) ($rawUsage['completion_tokens'] ?? $rawUsage['output_tokens'] ?? $usage['output_tokens'] ?? 0),
                    'total_tokens' => (int) ($rawUsage['total_tokens'] ?? $usage['total_tokens'] ?? 0),
                ];
                $onEvent('usage', ['usage' => $usage]);
            }
        };

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $chunk) use (&$buffer, $handleData): int {
                $buffer .= str_replace("\r\n", "\n", $chunk);
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $event = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    foreach (explode("\n", $event) as $line) {
                        if (str_starts_with($line, 'data:')) {
                            $handleData(ltrim(substr($line, 5)));
                        }
                    }
                }
                return strlen($chunk);
            },
        ]);

        $okCurl = curl_exec($curl);
        $curlErrno = curl_errno($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (trim($buffer) !== '') {
            foreach (explode("\n", $buffer) as $line) {
                if (str_starts_with($line, 'data:')) {
                    $handleData(ltrim(substr($line, 5)));
                }
            }
        }

        if ($curlErrno !== 0 || $okCurl === false) {
            $type = $curlErrno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'provider_unavailable';
            return $this->failure($started, $statusCode, '', null, $type, $curlError !== '' ? $curlError : 'Erreur réseau cURL pendant le streaming.');
        }

        if ($lastError !== null) {
            return [
                'ok' => false,
                'status_code' => $statusCode,
                'body' => (string) ($lastError['body'] ?? ''),
                'json' => null,
                'duration_ms' => $this->durationMs($started),
                'error_type' => (string) ($lastError['type'] ?? 'provider_unavailable'),
                'error_message' => (string) ($lastError['message'] ?? 'Erreur provider pendant le streaming.'),
                'provider_response_id' => $providerResponseId,
                'content' => $content,
                'usage' => $usage,
            ];
        }

        $errorType = $this->classifyError($statusCode, null, '');
        $ok = $statusCode >= 200 && $statusCode < 300 && $errorType === null;
        if ($ok && $content === '') {
            $ok = false;
            $errorType = 'invalid_response';
        }

        return [
            'ok' => $ok,
            'status_code' => $statusCode,
            'body' => '',
            'json' => null,
            'duration_ms' => $this->durationMs($started),
            'error_type' => $ok ? null : ($errorType ?: 'invalid_response'),
            'error_message' => $ok ? null : ($this->errorMessage(null, '', $statusCode, $errorType ?: 'invalid_response') ?: 'Réponse streamée vide ou non exploitable.'),
            'provider_response_id' => $providerResponseId,
            'content' => $content,
            'usage' => $usage,
        ];
    }

    /** @param array<string,mixed>|null $json */
    private function classifyError(int $statusCode, ?array $json, string $body): ?string
    {
        $message = strtolower($this->errorMessage($json, $body, $statusCode, null) ?? '');
        $code = strtolower((string) ($json['error']['code'] ?? $json['error']['type'] ?? ''));
        $haystack = $message . ' ' . $code;

        if ($statusCode === 0) {
            return 'provider_unavailable';
        }
        if ($statusCode === 401 || $statusCode === 403) {
            return 'auth_error';
        }
        if (str_contains($haystack, 'insufficient_quota') || str_contains($haystack, 'quota') || str_contains($haystack, 'billing')) {
            return 'quota_exceeded';
        }
        if ($statusCode === 429) {
            return 'rate_limited';
        }
        if ($statusCode === 400 || $statusCode === 422) {
            return 'bad_request';
        }
        if ($statusCode >= 500 && $statusCode <= 599) {
            return 'provider_unavailable';
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            return 'provider_unavailable';
        }
        return null;
    }

    /** @param array<string,mixed>|null $json */
    private function errorMessage(?array $json, string $body, int $statusCode, ?string $errorType): ?string
    {
        $message = $json['error']['message'] ?? $json['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }
        if ($body !== '') {
            return mb_substr(trim($body), 0, 500);
        }
        if ($errorType !== null) {
            return 'Erreur HTTP IA (' . $errorType . ', statut ' . $statusCode . ').';
        }
        return null;
    }

    /** @param array<string,mixed>|null $json */
    private function providerResponseId(?array $json): ?string
    {
        $id = $json['id'] ?? $json['response']['id'] ?? null;
        return is_string($id) && trim($id) !== '' ? trim($id) : null;
    }

    private function failure(float $started, int $statusCode, string $body, ?array $json, string $errorType, string $message): array
    {
        return [
            'ok' => false,
            'status_code' => $statusCode,
            'body' => $body,
            'json' => $json,
            'duration_ms' => $this->durationMs($started),
            'error_type' => $errorType,
            'error_message' => $message,
            'provider_response_id' => $this->providerResponseId($json),
        ];
    }

    private function durationMs(float $started): int
    {
        return max(0, (int) round((microtime(true) - $started) * 1000));
    }
}
