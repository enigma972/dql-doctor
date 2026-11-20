<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ChaosInjectCommand;
use App\DlqDoctor\Demo\Chaos\ChaosHighMessage;
use App\DlqDoctor\Demo\Chaos\ChaosLowMessage;
use App\DlqDoctor\Demo\Chaos\ChaosMediumMessage;
use App\DlqDoctor\Demo\Chaos\ChaosUnclassifiedMessage;
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
