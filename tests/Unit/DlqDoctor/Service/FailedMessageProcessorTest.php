<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Service;

use App\DlqDoctor\Attribute\Sensitivity;
use App\DlqDoctor\Entity\RepairProposal;
use App\DlqDoctor\Enum\DlqProcessingOutcome;
use App\DlqDoctor\Enum\PolicyDecision;
use App\DlqDoctor\Enum\SensitivityLevel;
use App\DlqDoctor\Messenger\TenantStamp;
use App\DlqDoctor\Service\AuditLoggerInterface;
use App\DlqDoctor\Service\FailedMessageProcessor;
use App\DlqDoctor\Service\FailedMessageTransport;
use App\DlqDoctor\Service\IdempotencyStore;
use App\DlqDoctor\Service\PolicyEvaluator;
use App\DlqDoctor\Service\SensitivityResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class FailedMessageProcessorTest extends TestCase
{
    public function testLowSensitivityFailureIsReplayedToAsyncAndAcknowledged(): void
    {
        $store = $this->createMock(FailedMessageTransport::class);
        $store->expects(self::once())->method('acknowledge');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::once())
            ->method('send')
            ->with(self::callback(static fn (Envelope $envelope): bool => null === $envelope->last(SentToFailureTransportStamp::class)
                && null === $envelope->last(ErrorDetailsStamp::class)
                && null === $envelope->last(TransportMessageIdStamp::class)))
            ->willReturnCallback(static fn (Envelope $envelope): Envelope => $envelope);
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::once())->method('claim')->willReturn(true);
        $idempotency->expects(self::once())->method('complete');
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::never())->method('log');

        $processor = $this->processor($store, $sender, $idempotency, $audit);

        self::assertSame(
            DlqProcessingOutcome::REPLAYED,
            $processor->process($this->envelope(new LowSensitivityFailure(), 'message-1'), 'tenant-a'),
        );
    }

    public function testHighSensitivityFailureCreatesApprovalProposalWithoutWaiting(): void
    {
        $store = $this->createMock(FailedMessageTransport::class);
        $store->expects(self::once())->method('acknowledge');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::never())->method('send');
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::once())->method('claim')->willReturn(true);
        $idempotency->expects(self::once())->method('complete');
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::once())->method('log')->with(
            'tenant-a',
            'approval_required',
            ['sensitivity' => 'high'],
            null,
            'message-2',
        );
        $normalizer = $this->createMock(NormalizerInterface::class);
        $normalizer->expects(self::once())->method('normalize')->willReturn(['reference' => 'safe-summary']);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            static fn (RepairProposal $proposal): bool => RepairProposal::class === $proposal::class
                && $proposal->status()->value === 'pending_approval',
        ));
        $entityManager->expects(self::once())->method('flush');

        $processor = $this->processor($store, $sender, $idempotency, $audit, $normalizer, $entityManager);

        self::assertSame(
            DlqProcessingOutcome::PROPOSAL_CREATED,
            $processor->process($this->envelope(new HighSensitivityFailure(), 'message-2'), 'tenant-a'),
        );
    }

    public function testCompletedIdempotencyClaimAcknowledgesStaleFailureWithoutReplayingAgain(): void
    {
        $store = $this->createMock(FailedMessageTransport::class);
        $store->expects(self::once())->method('acknowledge');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::never())->method('send');
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::once())->method('claim')->willReturn(false);
        $idempotency->expects(self::once())->method('result')->willReturn(['outcome' => 'replayed']);

        $processor = $this->processor(
            $store,
            $sender,
            $idempotency,
            $this->createStub(AuditLoggerInterface::class),
        );

        self::assertSame(
            DlqProcessingOutcome::ALREADY_PROCESSED,
            $processor->process($this->envelope(new LowSensitivityFailure(), 'message-3'), 'tenant-a'),
        );
    }

    public function testAnotherInProgressClaimIsLeftUnacknowledged(): void
    {
        $store = $this->createMock(FailedMessageTransport::class);
        $store->expects(self::never())->method('acknowledge');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::never())->method('send');
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::once())->method('claim')->willReturn(false);
        $idempotency->expects(self::once())->method('result')->willReturn(null);

        $processor = $this->processor(
            $store,
            $sender,
            $idempotency,
            $this->createStub(AuditLoggerInterface::class),
        );

        self::assertSame(
            DlqProcessingOutcome::IN_PROGRESS,
            $processor->process($this->envelope(new LowSensitivityFailure(), 'message-4'), 'tenant-a'),
        );
    }

    public function testMessageForAnotherTenantIsNotClaimedOrProcessed(): void
    {
        $store = $this->createMock(FailedMessageTransport::class);
        $store->expects(self::never())->method('acknowledge');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::never())->method('send');
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::never())->method('claim');

        $processor = $this->processor(
            $store,
            $sender,
            $idempotency,
            $this->createStub(AuditLoggerInterface::class),
        );

        self::assertSame(
            DlqProcessingOutcome::OTHER_TENANT,
            $processor->process($this->envelope(new LowSensitivityFailure(), 'message-5'), 'tenant-b'),
        );
    }

    public function testFailedAsyncSendLeavesFailureAndClaimForReconciliation(): void
    {
        $store = $this->createMock(FailedMessageTransport::class);
        $store->expects(self::never())->method('acknowledge');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::once())->method('send')->willThrowException(new RuntimeException('broker unavailable'));
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::once())->method('claim')->willReturn(true);
        $idempotency->expects(self::never())->method('complete');

        $processor = $this->processor(
            $store,
            $sender,
            $idempotency,
            $this->createStub(AuditLoggerInterface::class),
        );

        try {
            $processor->process($this->envelope(new LowSensitivityFailure(), 'message-6'), 'tenant-a');
            self::fail('The sender failure should be surfaced.');
        } catch (RuntimeException $exception) {
            self::assertSame('broker unavailable', $exception->getMessage());
        }
    }

    public function testMediumSensitivityReplayIsAudited(): void
    {
        $store = $this->createMock(FailedMessageTransport::class);
        $store->expects(self::once())->method('acknowledge');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::once())->method('send')->willReturnCallback(static fn (Envelope $envelope): Envelope => $envelope);
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::once())->method('claim')->willReturn(true);
        $idempotency->expects(self::once())->method('complete');
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects(self::once())->method('log')->with(
            'tenant-a',
            'replayed',
            ['sensitivity' => 'medium'],
            null,
            'message-7',
        );

        $processor = $this->processor($store, $sender, $idempotency, $audit);

        self::assertSame(
            DlqProcessingOutcome::REPLAYED,
            $processor->process($this->envelope(new MediumSensitivityFailure(), 'message-7'), 'tenant-a'),
        );
    }

    public function testForbiddenReplayIsNotClaimedOrRemoved(): void
    {
        $store = $this->createMock(FailedMessageTransport::class);
        $store->expects(self::never())->method('acknowledge');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::never())->method('send');
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::never())->method('claim');

        $policy = [
            'low' => ['replay' => 'auto', 'patch' => 'auto_audit', 'discard' => 'approval'],
            'medium' => ['replay' => 'auto_audit', 'patch' => 'approval', 'discard' => 'approval'],
            'high' => ['replay' => PolicyDecision::FORBIDDEN->value, 'patch' => 'approval', 'discard' => 'forbidden'],
        ];
        $processor = $this->processor(
            $store,
            $sender,
            $idempotency,
            $this->createStub(AuditLoggerInterface::class),
            policy: $policy,
        );

        self::assertSame(
            DlqProcessingOutcome::FORBIDDEN,
            $processor->process($this->envelope(new HighSensitivityFailure(), 'message-8'), 'tenant-a'),
        );
    }

    public function testOversizedApprovalPayloadIsRejectedBeforeClaiming(): void
    {
        $store = $this->createMock(FailedMessageTransport::class);
        $store->expects(self::never())->method('acknowledge');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::never())->method('send');
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::never())->method('claim');
        $normalizer = $this->createMock(NormalizerInterface::class);
        $normalizer->expects(self::once())->method('normalize')->willReturn(['payload' => str_repeat('x', 70_000)]);

        $processor = $this->processor(
            $store,
            $sender,
            $idempotency,
            $this->createStub(AuditLoggerInterface::class),
            $normalizer,
        );

        $this->expectException(\UnexpectedValueException::class);
        $processor->process($this->envelope(new HighSensitivityFailure(), 'message-9'), 'tenant-a');
    }

    public function testInvalidCompletedIdempotencyResultIsNotAcknowledged(): void
    {
        $store = $this->createMock(FailedMessageTransport::class);
        $store->expects(self::never())->method('acknowledge');
        $sender = $this->createMock(SenderInterface::class);
        $sender->expects(self::never())->method('send');
        $idempotency = $this->createMock(IdempotencyStore::class);
        $idempotency->expects(self::once())->method('claim')->willReturn(false);
        $idempotency->expects(self::once())->method('result')->willReturn(['outcome' => 'unknown']);

        $processor = $this->processor(
            $store,
            $sender,
            $idempotency,
            $this->createStub(AuditLoggerInterface::class),
        );

        $this->expectException(\UnexpectedValueException::class);
        $processor->process($this->envelope(new LowSensitivityFailure(), 'message-10'), 'tenant-a');
    }

    private function processor(
        FailedMessageTransport $store,
        SenderInterface $sender,
        IdempotencyStore $idempotency,
        AuditLoggerInterface $audit,
        ?NormalizerInterface $normalizer = null,
        ?EntityManagerInterface $entityManager = null,
        ?array $policy = null,
    ): FailedMessageProcessor {
        return new FailedMessageProcessor(
            $store,
            $sender,
            $idempotency,
            $audit,
            new PolicyEvaluator($policy ?? [
                'low' => ['replay' => 'auto', 'patch' => 'auto_audit', 'discard' => 'approval'],
                'medium' => ['replay' => 'auto_audit', 'patch' => 'approval', 'discard' => 'approval'],
                'high' => ['replay' => 'approval', 'patch' => 'approval', 'discard' => 'forbidden'],
            ]),
            new SensitivityResolver(),
            $normalizer ?? $this->createStub(NormalizerInterface::class),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
        );
    }

    private function envelope(object $message, string $messageId): Envelope
    {
        return new Envelope($message, [
            new TenantStamp('tenant-a'),
            new TransportMessageIdStamp($messageId),
            new SentToFailureTransportStamp('async'),
            new ErrorDetailsStamp(\RuntimeException::class, 0, 'private exception details'),
        ]);
    }
}

#[Sensitivity(SensitivityLevel::LOW)]
final class LowSensitivityFailure
{
}

#[Sensitivity(SensitivityLevel::HIGH)]
final class HighSensitivityFailure
{
    public function __construct(public readonly string $reference = 'safe-summary')
    {
    }
}

#[Sensitivity(SensitivityLevel::MEDIUM)]
final class MediumSensitivityFailure
{
}
