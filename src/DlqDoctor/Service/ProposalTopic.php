<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use InvalidArgumentException;

final class ProposalTopic
{
    private const BASE_TOPIC = 'http://localhost/dlq-doctor/proposals';

    public function forTenant(?string $tenantId): string
    {
        if (null === $tenantId) {
            return self::BASE_TOPIC;
        }

        if ('' === trim($tenantId) || strlen($tenantId) > 128) {
            throw new InvalidArgumentException('A proposal topic requires a tenant ID between 1 and 128 characters.');
        }

        return self::BASE_TOPIC.'/'.rawurlencode($tenantId);
    }

    public function tenantPattern(): string
    {
        return self::BASE_TOPIC.'/{tenantId}';
    }
}
