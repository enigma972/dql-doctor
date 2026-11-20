<?php

declare(strict_types=1);

namespace App\DlqDoctor\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class TenantStamp implements StampInterface
{
    public function __construct(public string $tenantId)
    {
        if ('' === trim($tenantId)) {
            throw new \InvalidArgumentException('A tenant ID cannot be empty.');
        }
    }
}
