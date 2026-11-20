<?php

declare(strict_types=1);

namespace App\DlqDoctor\Entity;

use App\DlqDoctor\Dto\RepairProposalSummary;
use App\DlqDoctor\Enum\RepairProposalStatus;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use DomainException;

#[ORM\Entity]
#[ORM\Table(name: 'dlq_repair_proposal')]
#[ORM\UniqueConstraint(name: 'uniq_dlq_proposal_tenant_idempotency', columns: ['tenant_id', 'idempotency_key'])]
#[ORM\UniqueConstraint(name: 'uniq_dlq_proposal_approval_token', columns: ['approval_token_hash'])]
#[ORM\Index(name: 'idx_dlq_proposal_tenant_status', columns: ['tenant_id', 'status'])]
final class RepairProposal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128)]
    private string $tenantId;

    #[ORM\Column(length: 128)]
    private string $failedMessageId;

    #[ORM\Column(length: 255)]
    private string $messageClass;

    #[ORM\Column(type: 'json')]
    private array $originalPayload;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $patchedPayload;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $diff;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $approvalTokenHash;

    #[ORM\Column(length: 128)]
    private string $idempotencyKey;

    #[ORM\Column(enumType: RepairProposalStatus::class)]
    private RepairProposalStatus $status = RepairProposalStatus::PENDING_APPROVAL;

    #[ORM\Column]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $tenantId,
        string $failedMessageId,
        string $messageClass,
        array $originalPayload,
        ?array $patchedPayload,
        ?array $diff,
        ?string $approvalTokenHash,
        string $idempotencyKey,
        DateTimeImmutable $expiresAt,
    ) {
        $this->tenantId = $tenantId;
        $this->failedMessageId = $failedMessageId;
        $this->messageClass = $messageClass;
        $this->originalPayload = $originalPayload;
        $this->patchedPayload = $patchedPayload;
        $this->diff = $diff;
        $this->approvalTokenHash = $approvalTokenHash;
        $this->idempotencyKey = $idempotencyKey;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function status(): RepairProposalStatus
    {
        return $this->status;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function failedMessageId(): string
    {
        return $this->failedMessageId;
    }

    public function messageClass(): string
    {
        return $this->messageClass;
    }

    /**
     * @return array<string|int, mixed>
     */
    public function originalPayload(): array
    {
        return $this->originalPayload;
    }

    /**
     * @return array<string|int, mixed>|null
     */
    public function patchedPayload(): ?array
    {
        return $this->patchedPayload;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function diff(): ?array
    {
        return $this->diff;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function issueApprovalToken(DateTimeImmutable $now): string
    {
        if (RepairProposalStatus::PENDING_APPROVAL !== $this->status || $this->expiresAt <= $now) {
            throw new DomainException('An approval token can only be issued for a pending, unexpired proposal.');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->approvalTokenHash = hash('sha256', $token);

        return $token;
    }

    public function expireIfDue(DateTimeImmutable $now): bool
    {
        if (RepairProposalStatus::PENDING_APPROVAL !== $this->status || $this->expiresAt > $now) {
            return false;
        }

        $this->status = RepairProposalStatus::EXPIRED;
        $this->approvalTokenHash = null;

        return true;
    }

    public function summary(): RepairProposalSummary
    {
        if (null === $this->id) {
            throw new DomainException('An unpersisted repair proposal has no public summary.');
        }

        return new RepairProposalSummary(
            $this->id,
            $this->failedMessageId,
            $this->messageClass,
            $this->status,
            $this->expiresAt,
            $this->createdAt,
        );
    }

    public function approve(string $token, DateTimeImmutable $now): void
    {
        $this->consumeApprovalToken($token, $now);
        $this->transitionFromPending(RepairProposalStatus::APPROVED);
    }

    public function assertApprovalToken(string $token, DateTimeImmutable $now): void
    {
        if (RepairProposalStatus::PENDING_APPROVAL !== $this->status) {
            throw new DomainException(sprintf('Cannot consume a token for a proposal in "%s" state.', $this->status->value));
        }

        if ($this->expiresAt <= $now) {
            throw new DomainException('The repair proposal has expired.');
        }

        if (null === $this->approvalTokenHash
            || '' === $token
            || !hash_equals($this->approvalTokenHash, hash('sha256', $token))
        ) {
            throw new DomainException('The approval token is invalid or has already been used.');
        }
    }

    public function reject(string $token, DateTimeImmutable $now): void
    {
        $this->consumeApprovalToken($token, $now);
        $this->transitionFromPending(RepairProposalStatus::REJECTED);
    }

    public function markReplayed(): void
    {
        if (RepairProposalStatus::APPROVED !== $this->status) {
            throw new DomainException('Only an approved proposal can be marked as replayed.');
        }

        $this->status = RepairProposalStatus::REPLAYED;
    }

    private function transitionFromPending(RepairProposalStatus $status): void
    {
        if (RepairProposalStatus::PENDING_APPROVAL !== $this->status) {
            throw new DomainException(sprintf('Cannot transition proposal from "%s" to "%s".', $this->status->value, $status->value));
        }

        $this->status = $status;
    }

    private function consumeApprovalToken(string $token, DateTimeImmutable $now): void
    {
        $this->assertApprovalToken($token, $now);
        $this->approvalTokenHash = null;
    }
}
