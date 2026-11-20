<?php

declare(strict_types=1);

namespace App\DlqDoctor\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'dlq_audit_log')]
#[ORM\Index(name: 'idx_dlq_audit_tenant_created', columns: ['tenant_id', 'created_at'])]
final class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private string $tenantId;

    #[ORM\Column(length: 64)]
    private string $action;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $actorId;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $failedMessageId;

    #[ORM\Column(type: 'json')]
    private array $context;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $tenantId,
        string $action,
        array $context = [],
        ?string $actorId = null,
        ?string $failedMessageId = null,
    ) {
        $this->tenantId = $tenantId;
        $this->action = $action;
        $this->actorId = $actorId;
        $this->failedMessageId = $failedMessageId;
        $this->context = $context;
        $this->createdAt = new DateTimeImmutable();
    }
}
