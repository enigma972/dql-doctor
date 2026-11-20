<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

interface AuditLoggerInterface
{
    public function log(
        string $tenantId,
        string $action,
        array $context = [],
        ?string $actorId = null,
        ?string $failedMessageId = null,
    ): void;
}
