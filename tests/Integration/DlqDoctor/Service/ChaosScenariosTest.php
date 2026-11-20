<?php

declare(strict_types=1);

namespace App\Tests\Integration\DlqDoctor\Service;

use App\DlqDoctor\Entity\AuditLog;
use App\DlqDoctor\Entity\RepairProposal;
use App\DlqDoctor\Enum\DlqProcessingOutcome;
use App\DlqDoctor\Enum\RepairProposalStatus;
use App\DlqDoctor\Enum\DlqAction;
use App\DlqDoctor\Enum\PolicyDecision;
use App\DlqDoctor\Messenger\GlobalTenantStamp;
use App\DlqDoctor\Service\DlqWatchService;
use App\DlqDoctor\Service\FailedMessageTransport;
use App\DlqDoctor\Service\PolicyEvaluator;
use App\DlqDoctor\Service\SensitivityResolver;
use App\DlqDoctor\Demo\Chaos\ChaosHighMessage;
use App\DlqDoctor\Demo\Chaos\ChaosLowMessage;
use App\DlqDoctor\Demo\Chaos\ChaosMediumMessage;
use App\DlqDoctor\Demo\Chaos\ChaosUnclassifiedMessage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

#[Group('integration')]
final class ChaosScenariosTest extends KernelTestCase
{
    public function testTheFourChaosScenariosFollowTheConfiguredPolicyWithoutBlockingForApproval(): void
    {
        $container = static::getContainer();
        $sender = $container->get('messenger.transport.failed');
        self::assertInstanceOf(SenderInterface::class, $sender);
        $transport = $container->get(FailedMessageTransport::class);
        self::assertInstanceOf(FailedMessageTransport::class, $transport);
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $messageIds = [];
        $outboxBaseline = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM dlq_mercure_outbox');

        try {
            foreach ([
                new ChaosLowMessage('chaos-low-'.bin2hex(random_bytes(6))),
                new ChaosMediumMessage('chaos-medium-'.bin2hex(random_bytes(6))),
                new ChaosHighMessage('chaos-high-'.bin2hex(random_bytes(6))),
                new ChaosUnclassifiedMessage('chaos-unclassified-'.bin2hex(random_bytes(6))),
            ] as $message) {
                $messageIds[] = (string) $sender->send(new Envelope($message, [
                    new GlobalTenantStamp(),
                    new ErrorDetailsStamp(\RuntimeException::class, 0, 'synthetic chaos failure'),
                ]))->last(TransportMessageIdStamp::class)->getId();
            }

            $watchService = $container->get(DlqWatchService::class);
            $outcomes = $watchService->processBatch(10);

            self::assertSame(1, $outcomes[DlqProcessingOutcome::REPLAYED->value]);
            self::assertSame(3, $outcomes[DlqProcessingOutcome::PROPOSAL_CREATED->value]);
            self::assertSame(0, $outcomes[DlqProcessingOutcome::FORBIDDEN->value]);
            self::assertSame(
                PolicyDecision::FORBIDDEN,
                $container->get(PolicyEvaluator::class)->evaluate(
                    $container->get(SensitivityResolver::class)->resolve(ChaosHighMessage::class),
                    DlqAction::DISCARD,
                ),
            );
            self::assertSame(
                \App\DlqDoctor\Enum\SensitivityLevel::HIGH,
                $container->get(SensitivityResolver::class)->resolve(ChaosUnclassifiedMessage::class),
            );

            $proposals = $entityManager->getRepository(RepairProposal::class)->findAll();
            $scenarioProposals = array_values(array_filter(
                $proposals,
                static fn (RepairProposal $proposal): bool => in_array($proposal->failedMessageId(), $messageIds, true),
            ));
            self::assertCount(3, $scenarioProposals);
            foreach ($scenarioProposals as $proposal) {
                self::assertSame(RepairProposalStatus::PENDING_APPROVAL, $proposal->status());
            }
            self::assertSame(
                3,
                $watchService->processBatch(10)[DlqProcessingOutcome::ALREADY_PROCESSED->value],
                'A repeated scan must not create duplicate approval proposals.',
            );

            $auditedReplay = $entityManager->getRepository(AuditLog::class)->findOneBy([
                'failedMessageId' => $messageIds[0],
                'action' => 'replayed',
            ]);
            self::assertNotNull($auditedReplay, 'The low-sensitivity automatic replay must be audited.');

            foreach (array_slice($messageIds, 1) as $messageId) {
                self::assertNotNull($transport->find($messageId), 'Approval-required messages must remain in the failure transport.');
            }
        } finally {
            foreach ($messageIds as $messageId) {
                if (null !== $envelope = $transport->find($messageId)) {
                    $transport->acknowledge($envelope);
                }
            }

            if ($entityManager->isOpen()) {
                foreach ($entityManager->getRepository(RepairProposal::class)->findBy(['failedMessageId' => $messageIds]) as $proposal) {
                    $entityManager->remove($proposal);
                }
                foreach ($entityManager->getRepository(AuditLog::class)->findBy(['failedMessageId' => $messageIds]) as $auditLog) {
                    $entityManager->remove($auditLog);
                }
                $entityManager->flush();

                $connection->executeStatement('DELETE FROM dlq_mercure_outbox WHERE id > ?', [$outboxBaseline]);
                $connection->executeStatement(
                    'DELETE FROM dlq_idempotency_record WHERE idempotency_key IN (?)',
                    [array_map(
                        static fn (string $messageId): string => hash('sha256', "replay\0".$messageId),
                        $messageIds,
                    )],
                    [\Doctrine\DBAL\ArrayParameterType::STRING],
                );
            }
        }
    }
}
