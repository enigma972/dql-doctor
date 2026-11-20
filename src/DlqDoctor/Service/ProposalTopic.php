<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use InvalidArgumentException;

final class ProposalTopic
{
    public function forTenant(string $tenantId): string
    {
        if ('' === trim($tenantId) || strlen($tenantId) > 128) {
            throw new InvalidArgumentException('A proposal topic requires a tenant ID between 1 and 128 characters.');
        }

        return 'http://localhost/proposals/'.rawurlencode($tenantId);
    }
}
