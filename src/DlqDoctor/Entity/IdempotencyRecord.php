<?php

declare(strict_types=1);

namespace App\DlqDoctor\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'dlq_idempotency_record')]
#[ORM\UniqueConstraint(name: 'uniq_dlq_idempotency_tenant_key', columns: ['tenant_id', 'idempotency_key'])]
final class IdempotencyRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private string $tenantId;

    #[ORM\Column(length: 128)]
    private string $idempotencyKey;

    #[ORM\Column(length: 64)]
    private string $action;

    #[ORM\Column(length: 32)]
    private string $status = 'started';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $result = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    public function __construct(string $tenantId, string $idempotencyKey, string $action)
    {
        $this->tenantId = $tenantId;
        $this->idempotencyKey = $idempotencyKey;
        $this->action = $action;
        $this->createdAt = new DateTimeImmutable();
    }
}
