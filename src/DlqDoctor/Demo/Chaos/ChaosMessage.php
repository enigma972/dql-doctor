<?php

declare(strict_types=1);

namespace App\DlqDoctor\Demo\Chaos;

use App\DlqDoctor\Messenger\TenantScopedMessage;

abstract class ChaosMessage implements TenantScopedMessage
{
    public int $attempts = 0;

    public function __construct(public readonly string $reference)
    {
    }
}
