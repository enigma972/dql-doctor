<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Entity\AuditLog;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AuditLogger implements AuditLoggerInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function log(
        string $tenantId,
        string $action,
        array $context = [],
        ?string $actorId = null,
        ?string $failedMessageId = null,
    ): void {
        $this->entityManager->persist(new AuditLog($tenantId, $action, $context, $actorId, $failedMessageId));
        $this->entityManager->flush();
    }
}
