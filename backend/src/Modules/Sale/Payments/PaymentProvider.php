<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

interface PaymentProvider
{
    public function key(): string;

    public function supports(string $operation): bool;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function createIntent(array $payload): array;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function recordPayment(array $payload): array;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function capture(array $payload): array;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function refund(array $payload): array;

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function void(array $payload): array;
}
