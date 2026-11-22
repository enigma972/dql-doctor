<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

interface AuditLoggerInterface
{
    public function log(
        string $action,
        array $context = [],
        ?string $failedMessageId = null,
    ): void;
}
