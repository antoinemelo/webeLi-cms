<?php

declare(strict_types=1);

namespace App\Core;

final class ApiException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        ?string $message = null,
        private readonly int $httpStatus = 0,
        private readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?? ErrorCode::message($errorCode), 0, $previous);
    }

    public function errorCode(): string { return $this->errorCode; }
    public function httpStatus(): int { return $this->httpStatus > 0 ? $this->httpStatus : ErrorCode::httpStatus($this->errorCode); }
    public function details(): array { return $this->details; }
    public function toResponse(): Response
    {
        if ($this->errorCode === ErrorCode::VALIDATION_FAILED && isset($this->details['fields']) && is_array($this->details['fields'])) {
            /** @var array<string,list<string>> $fields */
            $fields = $this->details['fields'];
            $details = $this->details;
            unset($details['fields']);
            return Response::validation($fields, $this->getMessage(), $this->httpStatus());
        }

        return Response::error($this->errorCode, $this->getMessage(), $this->httpStatus(), $this->details);
    }
}
