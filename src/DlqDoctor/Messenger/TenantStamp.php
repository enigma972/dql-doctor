<?php

declare(strict_types=1);

namespace App\DlqDoctor\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class TenantStamp implements StampInterface
{
    public function __construct(public string $tenantId)
    {
        if ('' === trim($tenantId) || strlen($tenantId) > 128) {
            throw new \InvalidArgumentException('A tenant ID must contain between 1 and 128 characters.');
        }
    }
}
