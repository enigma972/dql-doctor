<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Entity\AuditLog;
use App\DlqDoctor\Context\TenantContextInterface;
use App\DlqDoctor\Messenger\TenantScope;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use JsonException;

final readonly class AuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContextInterface $tenantContext,
    )
    {
    }

    public function log(
        string $action,
        array $context = [],
        ?string $failedMessageId = null,
    ): void {
        if ('' === trim($action) || strlen($action) > 64) {
            throw new InvalidArgumentException('An audit action must contain between 1 and 64 characters.');
        }

        try {
            $encodedContext = json_encode($context, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The audit context must be JSON serializable.', previous: $exception);
        }

        if (strlen($encodedContext) > 4096) {
            throw new InvalidArgumentException('The audit context exceeds the 4096-byte limit.');
        }

        if (null !== $failedMessageId && strlen($failedMessageId) > 128) {
            throw new InvalidArgumentException('An audit message ID cannot exceed 128 characters.');
        }

        $this->entityManager->persist(new AuditLog(
            TenantScope::requireTenantId($this->tenantContext),
            $action,
            $context,
            $this->tenantContext->actorId(),
            $failedMessageId,
        ));
        $this->entityManager->flush();
    }
}
