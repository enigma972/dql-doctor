<?php

declare(strict_types=1);

namespace App\Tests\Integration\DlqDoctor\Service;

use App\DlqDoctor\Attribute\Sensitivity;
use App\DlqDoctor\Entity\RepairProposal;
use App\DlqDoctor\Enum\DlqProcessingOutcome;
use App\DlqDoctor\Enum\RepairProposalStatus;
use App\DlqDoctor\Enum\SensitivityLevel;
use App\DlqDoctor\Messenger\TenantStamp;
use App\DlqDoctor\Service\AuditLogger;
use App\DlqDoctor\Service\FailedMessageProcessor;
use App\DlqDoctor\Service\FailedMessageTransport;
use App\DlqDoctor\Service\DlqWatchService;
use App\DlqDoctor\Service\IdempotencyManager;
use App\DlqDoctor\Service\PolicyEvaluator;
use App\DlqDoctor\Service\SensitivityResolver;
use App\Tests\Fixtures\DlqDoctor\FixedTenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class DlqWatchServiceTest extends KernelTestCase
{
    public function testWatchCreatesApprovalProposalsAndContinuesWithoutWaiting(): void
    {
        $container = static::getContainer();
        $tenantId = bin2hex(random_bytes(16));
        $sender = $container->get('messenger.transport.failed');
        self::assertInstanceOf(SenderInterface::class, $sender);

        $failedTransport = $container->get(FailedMessageTransport::class);
        self::assertInstanceOf(FailedMessageTransport::class, $failedTransport);
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $tenantContext = new FixedTenantContext($tenantId, 'test-actor');
        $processor = new FailedMessageProcessor(
            $failedTransport,
            $container->get('messenger.transport.async'),
            new IdempotencyManager($connection, $tenantContext),
            new AuditLogger($entityManager, $tenantContext),
            $container->get(PolicyEvaluator::class),
            $container->get(SensitivityResolver::class),
            $container->get(NormalizerInterface::class),
            $entityManager,
            $tenantContext,
        );
        $watchService = new DlqWatchService($failedTransport, $processor, $tenantContext);
        $messageIds = [];

        try {
            $messageIds[] = $this->sendApprovalFailure($sender, $tenantId, 'first');
            $messageIds[] = $this->sendApprovalFailure($sender, $tenantId, 'second');

            $firstEnvelope = $failedTransport->find($messageIds[0]);
            self::assertNotNull($firstEnvelope);

            $outcomes = $watchService->processBatch(20);

            self::assertSame(2, $outcomes[DlqProcessingOutcome::PROPOSAL_CREATED->value]);
            foreach ($messageIds as $messageId) {
                self::assertNull($failedTransport->find($messageId));
            }

            $proposals = $entityManager->getRepository(RepairProposal::class)->findBy(['tenantId' => $tenantId]);
            self::assertCount(2, $proposals);
            foreach ($proposals as $proposal) {
                self::assertSame(RepairProposalStatus::PENDING_APPROVAL, $proposal->status());
            }

            self::assertSame(
                DlqProcessingOutcome::ALREADY_PROCESSED,
                $processor->process($firstEnvelope),
            );
        } finally {
            foreach ($messageIds as $messageId) {
                if (null !== $envelope = $failedTransport->find($messageId)) {
                    $failedTransport->acknowledge($envelope);
                }
            }

            if ($entityManager->isOpen()) {
                foreach ($entityManager->getRepository(RepairProposal::class)->findBy(['tenantId' => $tenantId]) as $proposal) {
                    $entityManager->remove($proposal);
                }
                $entityManager->flush();

                $connection->delete('dlq_audit_log', ['tenant_id' => $tenantId]);
                $connection->delete('dlq_idempotency_record', ['tenant_id' => $tenantId]);
            }
        }
    }

    public function testAutomaticReplaySendsToAsyncAndAcknowledgesTheDoctrineFailure(): void
    {
        $container = static::getContainer();
        $tenantId = bin2hex(random_bytes(16));
        $sender = $container->get('messenger.transport.failed');
        self::assertInstanceOf(SenderInterface::class, $sender);
        $failedTransport = $container->get(FailedMessageTransport::class);
        self::assertInstanceOf(FailedMessageTransport::class, $failedTransport);
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $messageId = null;

        try {
            $sent = $sender->send(new Envelope(new AutomaticReplayFixtureMessage(), [
                new TenantStamp($tenantId),
                new SentToFailureTransportStamp('async'),
                new ErrorDetailsStamp(\RuntimeException::class, 0, 'private failure details'),
            ]));
            $messageId = $sent->last(TransportMessageIdStamp::class)?->getId();
            self::assertIsString($messageId);

            $failedEnvelope = $failedTransport->find($messageId);
            self::assertNotNull($failedEnvelope);
            $asyncTransport = new InMemoryTransport();
            $tenantContext = new FixedTenantContext($tenantId, 'test-actor');
            $processor = new FailedMessageProcessor(
                $failedTransport,
                $asyncTransport,
                new IdempotencyManager($connection, $tenantContext),
                new AuditLogger($entityManager, $tenantContext),
                $container->get(PolicyEvaluator::class),
                $container->get(SensitivityResolver::class),
                $container->get(NormalizerInterface::class),
                $entityManager,
                $tenantContext,
            );

            self::assertSame(DlqProcessingOutcome::REPLAYED, $processor->process($failedEnvelope));

            $replayed = $asyncTransport->get();
            self::assertCount(1, $replayed);
            self::assertSame($tenantId, $replayed[0]->last(TenantStamp::class)?->tenantId);
            self::assertNull($replayed[0]->last(ErrorDetailsStamp::class));
            self::assertNull($replayed[0]->last(SentToFailureTransportStamp::class));
            self::assertNull($failedTransport->find($messageId));
        } finally {
            if (is_string($messageId) && null !== $envelope = $failedTransport->find($messageId)) {
                $failedTransport->acknowledge($envelope);
            }

            $connection->delete('dlq_idempotency_record', ['tenant_id' => $tenantId]);
        }
    }

    private function sendApprovalFailure(SenderInterface $sender, string $tenantId, string $reference): string
    {
        $sent = $sender->send(new Envelope(new ApprovalFixtureMessage($reference), [
            new TenantStamp($tenantId),
            new ErrorDetailsStamp(\RuntimeException::class, 0, 'private failure details'),
        ]));
        $messageId = $sent->last(TransportMessageIdStamp::class)?->getId();
        self::assertIsString($messageId);

        return $messageId;
    }
}

#[Sensitivity(SensitivityLevel::HIGH)]
final readonly class ApprovalFixtureMessage
{
    public function __construct(public string $reference)
    {
    }
}

#[Sensitivity(SensitivityLevel::LOW)]
final class AutomaticReplayFixtureMessage
{
}
