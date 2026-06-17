<?php

declare(strict_types=1);

namespace App\Mail;

final class PhpMailMailer implements MailerInterface
{
    public function __construct(private readonly array $config) {}

    public function send(string $to, string $subject, string $textBody, ?string $htmlBody = null): bool
    {
        $to = trim($to);
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $transport = strtolower((string) ($this->config['mail']['transport'] ?? 'mail'));
        if ($transport === 'log') {
            return $this->logMail($to, $subject, $textBody, $htmlBody);
        }
        if ($transport === 'null' || $transport === 'disabled') {
            return true;
        }

        $fromEmail = trim((string) ($this->config['mail']['from_email'] ?? 'no-reply@example.test'));
        $fromName = trim((string) ($this->config['mail']['from_name'] ?? 'DEC CMS'));
        $from = $fromName !== '' ? sprintf('"%s" <%s>', addcslashes($fromName, '"\\'), $fromEmail) : $fromEmail;

        $headers = [
            'From: ' . $from,
            'MIME-Version: 1.0',
        ];

        if ($htmlBody !== null && trim($htmlBody) !== '') {
            $boundary = 'amcms-' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                . $textBody . "\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
                . $htmlBody . "\r\n"
                . "--{$boundary}--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $body = $textBody;
        }

        return @mail($to, $this->sanitizeHeader($subject), $body, implode("\r\n", $headers));
    }

    private function logMail(string $to, string $subject, string $textBody, ?string $htmlBody): bool
    {
        $path = \base_path('storage/logs/mail.log');
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $payload = [
            'created_at' => gmdate('Y-m-d H:i:s'),
            'to' => $to,
            'subject' => $subject,
            'text' => $textBody,
            'html' => $htmlBody,
        ];
        return file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }

    private function sanitizeHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }
}
