<?php

declare(strict_types=1);

namespace App\Modules\Sale\Payments;

interface RevolutGateway
{
    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function createOrder(array $params, string $idempotencyKey): array;

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function updateOrder(string $id, array $params): array;

    /** @return array<string,mixed> */
    public function retrieveOrder(string $id): array;

    /** @return array<string,mixed> */
    public function cancelOrder(string $id): array;

}
