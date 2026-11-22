<?php

declare(strict_types=1);

namespace App\DlqDoctor\Demo;

use App\DlqDoctor\Messenger\TenantScopedMessage;

final readonly class DemoMessage implements TenantScopedMessage
{
    public function __construct(public string $reference)
    {
    }
}
