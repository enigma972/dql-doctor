<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Service;

use App\DlqDoctor\Attribute\Sensitivity;
use App\DlqDoctor\Enum\SensitivityLevel;
use App\DlqDoctor\Messenger\TenantStamp;
use App\DlqDoctor\Service\FailedQueueManager;
use App\DlqDoctor\Service\FailedMessageTransport;
use App\DlqDoctor\Service\SensitivityResolver;
use App\Tests\Fixtures\DlqDoctor\FixedTenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

final class FailedQueueManagerTest extends TestCase
{
    public function testItOnlyListsFailuresBelongingToTheCurrentTenant(): void
    {
        $receiver = $this->createStub(FailedMessageTransport::class);
        $receiver->method('all')->willReturn([
            new Envelope(new LowSensitivityMessage(), [new TenantStamp('tenant-a'), new TransportMessageIdStamp('1')]),
            new Envelope(new LowSensitivityMessage(), [new TenantStamp('tenant-b'), new TransportMessageIdStamp('2')]),
            new Envelope(new LowSensitivityMessage(), [new TransportMessageIdStamp('3')]),
        ]);

        $failures = $this->manager($receiver)->listForCurrentTenant();

        self::assertCount(1, $failures);
        self::assertSame('1', $failures[0]->id);
        self::assertSame(LowSensitivityMessage::class, $failures[0]->messageClass);
        self::assertSame(SensitivityLevel::LOW, $failures[0]->sensitivity);
        self::assertNull($failures[0]->errorClass);
    }

    public function testItDoesNotReturnASummaryForAnotherTenant(): void
    {
        $receiver = $this->createStub(FailedMessageTransport::class);
        $receiver->method('find')->willReturn(
            new Envelope(new LowSensitivityMessage(), [new TenantStamp('tenant-b'), new TransportMessageIdStamp('2')]),
        );

        $manager = $this->manager($receiver, 'tenant-a');

        self::assertNull($manager->findSummaryForCurrentTenant('2'));
        self::assertSame('2', $this->manager($receiver, 'tenant-b')->findSummaryForCurrentTenant('2')?->id);
    }

    public function testItRequiresAnAuthorizedViewer(): void
    {
        $receiver = $this->createStub(FailedMessageTransport::class);
        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('isGranted')->with('ROLE_DLQ_VIEWER')->willReturn(false);
        $manager = new FailedQueueManager($receiver, new SensitivityResolver(), new FixedTenantContext('tenant-a'), $security);

        $this->expectException(AccessDeniedException::class);
        $manager->listForCurrentTenant();
    }

    private function manager(FailedMessageTransport $transport, string $tenantId = 'tenant-a'): FailedQueueManager
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);

        return new FailedQueueManager($transport, new SensitivityResolver(), new FixedTenantContext($tenantId), $security);
    }
}

#[Sensitivity(SensitivityLevel::LOW)]
final class LowSensitivityMessage
{
}
