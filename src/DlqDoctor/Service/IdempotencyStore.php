<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

interface IdempotencyStore
{
    public function claim(string $tenantId, string $key, string $action): bool;

    public function complete(string $tenantId, string $key, array $result): void;

    public function result(string $tenantId, string $key): ?array;
}
