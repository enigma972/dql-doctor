<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

interface IdempotencyStore
{
    public function claim(string $key, string $action): bool;

    public function complete(string $key, array $result): void;

    public function result(string $key): ?array;
}
