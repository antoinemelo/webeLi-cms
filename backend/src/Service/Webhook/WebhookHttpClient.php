<?php

declare(strict_types=1);

namespace App\Service\Webhook;

final class WebhookHttpClient
{
    /** @param array<string,string> $headers */
    public function postJson(string $url, string $body, array $headers, int $timeout = 10): array
    {
        $headerLines = ['Content-Type: application/json', 'User-Agent: DEC CMS-Webhook/1.0'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $responseBody = curl_exec($ch);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($responseBody === false) {
                throw new \RuntimeException($this->formatCurlError($errno, $error, $url));
            }
            return ['status' => $status, 'body' => (string) $responseBody];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \RuntimeException($message);
        });
        try {
            $responseBody = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }
        $status = 0;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) {
                $status = (int) $m[1];
                break;
            }
        }
        if ($responseBody === false) {
            throw new \RuntimeException($this->formatGenericError('Webhook HTTP request failed.', $url));
        }
        return ['status' => $status, 'body' => (string) $responseBody];
    }

    private function formatCurlError(int $errno, string $error, string $url): string
    {
        $message = $error !== '' ? $error : 'Webhook HTTP request failed.';
        if (in_array($errno, [51, 60, 83], true) || preg_match('/SSL|certificate|subject name|hostname|verify/i', $message)) {
            return $this->formatSslError($message, $url);
        }
        return $this->formatGenericError($message, $url);
    }

    private function formatSslError(string $message, string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        return sprintf(
            'SSL: %s. Vérifiez que le certificat HTTPS présenté par %s contient ce nom de domaine dans ses Subject Alternative Names (SAN), que le DNS pointe vers le bon serveur et que le vhost HTTPS sert le bon certificat.',
            $message,
            $host
        );
    }

    private function formatGenericError(string $message, string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        return sprintf('%s Endpoint: %s.', $message, $host);
    }
}
