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
        $token = $proposal->issueApprovalToken(new \DateTimeImmutable());
        $proposal->approve($token, new \DateTimeImmutable());

        self::assertSame(RepairProposalStatus::APPROVED, $proposal->status());

        $this->expectException(\DomainException::class);
        $proposal->approve($token, new \DateTimeImmutable());
    }

    public function testApprovedProposalCanBeMarkedReplayed(): void
    {
        $proposal = self::proposal();
        $proposal->approve(
            $proposal->issueApprovalToken(new \DateTimeImmutable()),
            new \DateTimeImmutable(),
        );
        $proposal->markReplayed();

        self::assertSame(RepairProposalStatus::REPLAYED, $proposal->status());
    }

    public function testApprovalTokenIsSingleUseAndNeverReturnedAsAHash(): void
    {
        $proposal = self::proposal();
        $token = $proposal->issueApprovalToken(new \DateTimeImmutable());

        self::assertNotSame(hash('sha256', $token), $token);

        $this->expectException(\DomainException::class);
        $proposal->approve('incorrect-token', new \DateTimeImmutable());
    }

    public function testExpiredProposalCannotBeApprovedAndTransitionsToExpired(): void
    {
        $proposal = self::proposal(expiresAt: new \DateTimeImmutable('-1 second'));
        $token = $proposal->issueApprovalToken(new \DateTimeImmutable('-1 minute'));

        self::assertTrue($proposal->expireIfDue(new \DateTimeImmutable()));
        self::assertSame(RepairProposalStatus::EXPIRED, $proposal->status());

        $this->expectException(\DomainException::class);
        $proposal->approve($token, new \DateTimeImmutable());
    }

    private static function proposal(?\DateTimeImmutable $expiresAt = null): RepairProposal
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
            expiresAt: $expiresAt ?? new \DateTimeImmutable('+15 minutes'),
        );
    }
}
