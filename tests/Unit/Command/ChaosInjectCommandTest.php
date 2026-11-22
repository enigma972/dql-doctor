<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ChaosInjectCommand;
use App\DlqDoctor\Demo\Chaos\RepairableChaosHighMessage as ChaosHighMessage;
use App\DlqDoctor\Demo\Chaos\ChaosLowMessage;
use App\DlqDoctor\Demo\Chaos\RepairableChaosMediumMessage as ChaosMediumMessage;
use App\DlqDoctor\Demo\Chaos\RepairableChaosUnclassifiedMessage as ChaosUnclassifiedMessage;
use App\DlqDoctor\Messenger\GlobalTenantStamp;
use App\Tests\Fixtures\DlqDoctor\FixedTenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ChaosInjectCommandTest extends TestCase
{
    public function testNewScenariosRequireRepairEvenAfterRetriesAreExhausted(): void
    {
        $handler = new \App\DlqDoctor\Demo\Chaos\ChaosMessageHandler();
        foreach ([
            [new ChaosMediumMessage('medium'), 'handleRepairableMedium'],
            [new ChaosHighMessage('high'), 'handleRepairableHigh'],
            [new ChaosUnclassifiedMessage('unclassified'), 'handleRepairableUnclassified'],
        ] as [$message, $method]) {
            $message->attempts = 100;
            try {
                $handler->$method($message);
                self::fail('An unrepaired scenario must still fail after retry exhaustion.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('status must be ready', $exception->getMessage());
            }
            $message->status = 'ready';
            $handler->$method($message);
            self::assertSame('ready', $message->status);
        }

        $legacy = new \App\DlqDoctor\Demo\Chaos\ChaosHighMessage('legacy');
        $legacy->attempts = 4;
        $handler->handleHigh($legacy);
        self::assertSame(5, $legacy->attempts);
    }

    public function testItDispatchesAllFourGlobalChaosScenarios(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::exactly(4))
            ->method('dispatch')
            ->willReturnCallback(static function (object $message, array $stamps): Envelope {
                self::assertContains(
                    $message::class,
                    [
                        ChaosLowMessage::class,
                        ChaosMediumMessage::class,
                        ChaosHighMessage::class,
                        ChaosUnclassifiedMessage::class,
                    ],
                );
                self::assertCount(1, $stamps);
                self::assertInstanceOf(GlobalTenantStamp::class, $stamps[0]);

                return new Envelope($message, $stamps);
            });
        $output = new BufferedOutput();

        $status = (new ChaosInjectCommand(new FixedTenantContext(null), $bus))->__invoke(
            new SymfonyStyle(new ArrayInput([]), $output),
        );

        self::assertSame(0, $status);
        self::assertStringContainsString('low, medium, high, and unclassified', $output->fetch());
    }

    public function testItRefusesToInjectChaosForATenantScopedProcess(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $output = new BufferedOutput();

        $status = (new ChaosInjectCommand(new FixedTenantContext('tenant-1'), $bus))->__invoke(
            new SymfonyStyle(new ArrayInput([]), $output),
        );

        self::assertSame(1, $status);
        self::assertStringContainsString('global demo scope', $output->fetch());
    }
}
