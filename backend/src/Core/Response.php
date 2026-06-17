<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    private static ?string $requestId = null;

    public function __construct(
        private readonly int $status,
        private readonly string $body,
        private readonly array $headers = ['Content-Type' => 'text/html; charset=utf-8']
    ) {}

    public function status(): int { return $this->status; }
    public function body(): string { return $this->body; }
    public function headers(): array { return $this->headers; }

    /** @param array<string,string> $headers */
    public function withHeaders(array $headers): self
    {
        $merged = $this->headers;

        foreach ($headers as $name => $value) {
            $normalizedName = trim((string) $name);
            if ($normalizedName === '') {
                continue;
            }

            foreach (array_keys($merged) as $existingName) {
                if (strcasecmp((string) $existingName, $normalizedName) === 0) {
                    unset($merged[$existingName]);
                }
            }

            $merged[$normalizedName] = (string) $value;
        }

        return new self($this->status, $this->body, $merged);
    }

    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $body, $headers + ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        return self::apiPayload(self::normalizeJsonPayload($payload, $status), $status, $headers);
    }

    /** @param array<string,mixed> $meta */
    public static function success(mixed $data, string $contract, array $meta = [], int $status = 200, array $headers = []): self
    {
        return self::apiPayload([
            'data' => $data,
            'meta' => self::meta($contract, $meta),
        ], $status, $headers);
    }

    /** @param array<string,mixed> $details */
    public static function error(string $code, string $message, int $status = 400, array $details = [], array $headers = []): self
    {
        return self::apiPayload(self::errorPayload($code, $message, $details), $status, $headers);
    }

    /** @param array<string,list<string>> $fields */
    public static function validation(array $fields, string $message = 'Le contenu contient des erreurs.', int $status = 422, array $headers = []): self
    {
        return self::apiPayload(self::errorPayload(ErrorCode::VALIDATION_FAILED, $message, [], $fields), $status, $headers);
    }

    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = self::newRequestId();
        }
        return self::$requestId;
    }

    public static function resetRequestId(?string $requestId = null): void
    {
        self::$requestId = $requestId !== null && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $requestId) ? $requestId : self::newRequestId();
    }

    /** @param array<string,mixed> $meta */
    private static function meta(string $contract, array $meta = []): array
    {
        return ['contract' => $contract, 'request_id' => self::requestId()] + $meta;
    }

    /**
     * @param array<string,mixed> $details
     * @param array<string,list<string>> $fields
     * @return array{error:array<string,mixed>,meta:array<string,string>}
     */
    private static function errorPayload(string $code, string $message = '', array $details = [], array $fields = []): array
    {
        $strictCode = self::strictCode($code);
        $payload = [
            'error' => [
                'code' => $strictCode,
                'message' => $message !== '' ? $message : self::defaultErrorMessage($strictCode),
                'details' => (object) $details,
                'request_id' => self::requestId(),
            ],
            'meta' => self::meta('error.v1'),
        ];

        if ($fields !== []) {
            $payload['error']['fields'] = $fields;
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private static function apiPayload(array $payload, int $status, array $headers): self
    {
        $body = json_encode($payload, self::JSON_FLAGS);
        if ($body === false) {
            $body = '{"error":{"code":"' . ErrorCode::JSON_ENCODING_FAILED . '","message":"' . ErrorCode::message(ErrorCode::JSON_ENCODING_FAILED) . '","details":{},"request_id":"' . self::requestId() . '"},"meta":{"contract":"error.v1","request_id":"' . self::requestId() . '"}}';
            $status = 500;
        }

        return new self($status, $body, $headers + [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Request-Id' => self::requestId(),
        ]);
    }

    /** @param array<string,mixed> $payload */
    private static function normalizeJsonPayload(array $payload, int $status): array
    {
        if (isset($payload['error'])) {
            if (is_array($payload['error']) && isset($payload['error']['code'])) {
                $error = $payload['error'];
                $error['code'] = self::strictCode((string) $error['code']);
                $error['message'] = (string) ($error['message'] ?? self::defaultErrorMessage((string) $error['code']));
                $error['request_id'] = (string) ($error['request_id'] ?? self::requestId());
                if (!isset($error['details'])) {
                    $error['details'] = (object) [];
                }
                return ['error' => $error, 'meta' => self::meta('error.v1')];
            }

            $code = is_string($payload['error']) ? self::strictCode($payload['error']) : ErrorCode::API_ERROR;
            return self::errorPayload(
                $code,
                (string) ($payload['message'] ?? self::defaultErrorMessage($code)),
                array_diff_key($payload, ['error' => true, 'message' => true])
            );
        }

        $meta = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : [];
        $contract = (string) ($meta['contract'] ?? ($status >= 400 ? 'error.v1' : 'response.v1'));
        unset($meta['contract']);

        return [
            'data' => $payload['data'] ?? $payload,
            'meta' => self::meta($contract, $meta),
        ];
    }

    private static function strictCode(string $code): string
    {
        return ErrorCode::isKnown($code) ? $code : ErrorCode::API_ERROR;
    }

    private static function defaultErrorMessage(string $code): string
    {
        return ErrorCode::message(self::strictCode($code));
    }

    private static function newRequestId(): string
    {
        try {
            return sprintf('%s-%s', gmdate('YmdHis'), bin2hex(random_bytes(8)));
        } catch (\Throwable) {
            return uniqid('req_', true);
        }
    }
}
