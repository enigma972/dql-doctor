<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Service;

use App\DlqDoctor\Attribute\Sensitivity;
use App\DlqDoctor\Enum\SensitivityLevel;
use App\DlqDoctor\Messenger\TenantStamp;
use App\DlqDoctor\Service\FailedQueueManager;
use App\DlqDoctor\Service\SensitivityResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

final class FailedQueueManagerTest extends TestCase
{
    public function testItOnlyListsFailuresBelongingToTheRequestedTenant(): void
    {
        $receiver = $this->createStub(ListableReceiverInterface::class);
        $receiver->method('all')->willReturn([
            new Envelope(new LowSensitivityMessage(), [new TenantStamp('tenant-a'), new TransportMessageIdStamp('1')]),
            new Envelope(new LowSensitivityMessage(), [new TenantStamp('tenant-b'), new TransportMessageIdStamp('2')]),
            new Envelope(new LowSensitivityMessage(), [new TransportMessageIdStamp('3')]),
        ]);

        $failures = (new FailedQueueManager($receiver, new SensitivityResolver()))->listForTenant('tenant-a');

        self::assertCount(1, $failures);
        self::assertSame('1', $failures[0]->id);
        self::assertSame(LowSensitivityMessage::class, $failures[0]->messageClass);
        self::assertSame(SensitivityLevel::LOW, $failures[0]->sensitivity);
        self::assertNull($failures[0]->errorClass);
    }

    public function testItDoesNotReturnAnEnvelopeForAnotherTenant(): void
    {
        $receiver = $this->createStub(ListableReceiverInterface::class);
        $receiver->method('find')->willReturn(
            new Envelope(new LowSensitivityMessage(), [new TenantStamp('tenant-b'), new TransportMessageIdStamp('2')]),
        );

        $manager = new FailedQueueManager($receiver, new SensitivityResolver());

        self::assertNull($manager->findForTenant('2', 'tenant-a'));
        self::assertNotNull($manager->findForTenant('2', 'tenant-b'));
    }
}

#[Sensitivity(SensitivityLevel::LOW)]
final class LowSensitivityMessage
{
}
