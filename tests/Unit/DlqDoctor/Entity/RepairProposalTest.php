<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Entity;

use App\DlqDoctor\Entity\RepairProposal;
use App\DlqDoctor\Enum\RepairProposalStatus;
use PHPUnit\Framework\TestCase;

final class RepairProposalTest extends TestCase
{
    public function testProposalMustBeApprovedBeforeItCanBeReplayed(): void
    {
        $proposal = self::proposal();

        $this->expectException(\DomainException::class);
        $proposal->markReplayed();
    }

    public function testProposalCanOnlyBeApprovedOnce(): void
    {
        $proposal = self::proposal();
        $proposal->approve();

        self::assertSame(RepairProposalStatus::APPROVED, $proposal->status());

        $this->expectException(\DomainException::class);
        $proposal->approve();
    }

    public function testApprovedProposalCanBeMarkedReplayed(): void
    {
        $proposal = self::proposal();
        $proposal->approve();
        $proposal->markReplayed();

        self::assertSame(RepairProposalStatus::REPLAYED, $proposal->status());
    }

    private static function proposal(): RepairProposal
    {
        return new RepairProposal(
            tenantId: 'tenant-a',
            failedMessageId: '42',
            messageClass: 'App\\Message\\ExampleMessage',
            originalPayload: ['id' => 42],
            patchedPayload: ['id' => 42],
            diff: [],
            approvalTokenHash: hash('sha256', 'token'),
            idempotencyKey: 'key-42',
            expiresAt: new \DateTimeImmutable('+15 minutes'),
        );
    }
}
