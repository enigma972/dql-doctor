<?php

declare(strict_types=1);

namespace App\Tests\Integration\DlqDoctor\Service;

use App\DlqDoctor\Attribute\Sensitivity;
use App\DlqDoctor\Enum\SensitivityLevel;
use App\DlqDoctor\Messenger\TenantStamp;
use App\DlqDoctor\Service\FailedQueueManager;
use App\DlqDoctor\Service\FailedMessageTransport;
use App\DlqDoctor\Service\SensitivityResolver;
use App\Tests\Fixtures\DlqDoctor\FixedTenantContext;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final class FailedQueueManagerTest extends KernelTestCase
{
    public function testTenantStampAndFailureDetailsSurviveTheDoctrineTransport(): void
    {
        $tenantId = bin2hex(random_bytes(16));
        $container = static::getContainer();
        $sender = $container->get('messenger.transport.failed');
        $receiver = $container->get('messenger.transport.failed');
        self::assertInstanceOf(SenderInterface::class, $sender);
        self::assertInstanceOf(ListableReceiverInterface::class, $receiver);

        $sent = $sender->send(new Envelope(
            new FailedFixtureMessage(),
            [
                new TenantStamp($tenantId),
                new ErrorDetailsStamp(\RuntimeException::class, 0, 'sensitive failure text'),
            ],
        ));
        $messageId = $sent->last(TransportMessageIdStamp::class)?->getId();
        self::assertIsString($messageId);

        $failedMessageTransport = $container->get(FailedMessageTransport::class);
        self::assertInstanceOf(FailedMessageTransport::class, $failedMessageTransport);
        $security = $this->createMock(Security::class);
        $security->expects(self::atLeastOnce())->method('isGranted')->with('ROLE_DLQ_VIEWER')->willReturn(true);
        $manager = new FailedQueueManager($failedMessageTransport, new SensitivityResolver(), new FixedTenantContext($tenantId), $security);
        $failure = $failedMessageTransport->find($messageId);
        $summary = $manager->findSummaryForCurrentTenant($messageId);

        try {
            self::assertNotNull($failure);
            self::assertSame($tenantId, $failure->last(TenantStamp::class)?->tenantId);
            self::assertNotNull($summary);
            self::assertSame($messageId, $summary->id);
            self::assertFalse(property_exists($summary, 'payload'));

            $summaries = $manager->listForCurrentTenant();
            self::assertCount(1, array_filter($summaries, static fn ($summary): bool => $summary->id === $messageId));
        } finally {
            if (null !== $failure) {
                $failedMessageTransport->acknowledge($failure);
            }
        }

        self::assertNull($failedMessageTransport->find($messageId));
    }
}

#[Sensitivity(SensitivityLevel::LOW)]
final class FailedFixtureMessage
{
}
