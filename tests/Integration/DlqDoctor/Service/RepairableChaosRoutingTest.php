<?php

declare(strict_types=1);

namespace App\Tests\Integration\DlqDoctor\Service;

use App\DlqDoctor\Demo\Chaos\RepairableChaosHighMessage;
use App\DlqDoctor\Demo\Chaos\RepairableChaosMediumMessage;
use App\DlqDoctor\Demo\Chaos\RepairableChaosUnclassifiedMessage;
use App\DlqDoctor\Messenger\GlobalTenantStamp;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RepairableChaosRoutingTest extends KernelTestCase
{
    public function testRepairableScenariosAreQueuedRatherThanHandledDuringInjection(): void
    {
        $container = static::getContainer();
        $bus = $container->get(MessageBusInterface::class);
        $transport = $container->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        foreach ([
            new RepairableChaosMediumMessage('medium'),
            new RepairableChaosHighMessage('high'),
            new RepairableChaosUnclassifiedMessage('unclassified'),
        ] as $message) {
            $bus->dispatch($message, [new GlobalTenantStamp()]);
            self::assertSame(0, $message->attempts);
        }

        self::assertCount(3, $transport->getSent());
    }
}
