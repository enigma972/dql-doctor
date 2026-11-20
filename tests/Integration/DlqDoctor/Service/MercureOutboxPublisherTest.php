<?php

declare(strict_types=1);

namespace App\Tests\Integration\DlqDoctor\Service;

use App\DlqDoctor\Entity\MercureOutboxMessage;
use App\DlqDoctor\Service\MercureOutboxPublisher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Mercure\Exception\RuntimeException;

#[Group('integration')]
final class MercureOutboxPublisherTest extends KernelTestCase
{
    public function testPublisherDeliversPrivateEventsAndMarksThemPublished(): void
    {
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $tenantId = bin2hex(random_bytes(16));
        $message = new MercureOutboxMessage(
            $tenantId,
            'http://localhost/proposals/'.$tenantId,
            'proposal.updated',
            ['proposalId' => 42, 'status' => 'pending_approval'],
        );
        $entityManager->persist($message);
        $entityManager->flush();

        try {
            $hub = $this->createMock(HubInterface::class);
            $hub->expects(self::once())
                ->method('publish')
                ->with(self::callback(static function (Update $update) use ($tenantId): bool {
                    $data = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);

                    return $update->isPrivate()
                        && ['http://localhost/proposals/'.$tenantId] === $update->getTopics()
                        && 'proposal.updated' === $update->getType()
                        && ['proposalId' => 42, 'status' => 'pending_approval'] === $data;
                }))
                ->willReturn('mercure-event-id');

            self::assertSame(1, (new MercureOutboxPublisher($connection, $hub))->publishPending());
            self::assertNotFalse($connection->fetchOne(
                'SELECT published_at FROM dlq_mercure_outbox WHERE id = ?',
                [$message->id()],
            ));
        } finally {
            $entityManager->remove($message);
            $entityManager->flush();
        }
    }

    public function testPublisherDeliversGlobalEvents(): void
    {
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $message = new MercureOutboxMessage(
            null,
            'http://localhost/proposals',
            'proposal.updated',
            ['proposalId' => 42, 'status' => 'pending_approval'],
        );
        $entityManager->persist($message);
        $entityManager->flush();

        try {
            $hub = $this->createMock(HubInterface::class);
            $hub->expects(self::once())
                ->method('publish')
                ->with(self::callback(static function (Update $update): bool {
                    return $update->isPrivate()
                        && ['http://localhost/proposals'] === $update->getTopics();
                }))
                ->willReturn('mercure-global-event-id');

            self::assertSame(1, (new MercureOutboxPublisher($connection, $hub))->publishPending());
        } finally {
            $entityManager->remove($message);
            $entityManager->flush();
        }
    }

    public function testPublisherRejectsAnInvalidLimit(): void
    {
        $publisher = new MercureOutboxPublisher(
            static::getContainer()->get(Connection::class),
            $this->createStub(HubInterface::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $publisher->publishPending(1001);
    }

    public function testFailedPublishLeavesTheOutboxEventAvailableForRetry(): void
    {
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $connection = $container->get(Connection::class);
        $tenantId = bin2hex(random_bytes(16));
        $message = new MercureOutboxMessage(
            $tenantId,
            'http://localhost/proposals/'.$tenantId,
            'proposal.updated',
            ['status' => 'rejected'],
        );
        $entityManager->persist($message);
        $entityManager->flush();

        try {
            $hub = $this->createMock(HubInterface::class);
            $hub->expects(self::once())
                ->method('publish')
                ->willThrowException(new RuntimeException('Mercure is unavailable.'));

            try {
                (new MercureOutboxPublisher($connection, $hub))->publishPending();
                self::fail('A failed Mercure request must be surfaced.');
            } catch (RuntimeException $exception) {
                self::assertSame('Mercure is unavailable.', $exception->getMessage());
            }

            self::assertNull($connection->fetchOne(
                'SELECT published_at FROM dlq_mercure_outbox WHERE id = ?',
                [$message->id()],
            ));
        } finally {
            $entityManager->remove($message);
            $entityManager->flush();
        }
    }
}
