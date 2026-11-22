<?php

declare(strict_types=1);

namespace App\Command;

use App\DlqDoctor\Context\TenantContextInterface;
use App\DlqDoctor\Demo\Chaos\ChaosHighMessage;
use App\DlqDoctor\Demo\Chaos\ChaosLowMessage;
use App\DlqDoctor\Demo\Chaos\ChaosMediumMessage;
use App\DlqDoctor\Demo\Chaos\ChaosUnclassifiedMessage;
use App\DlqDoctor\Messenger\GlobalTenantStamp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Messenger\MessageBusInterface;

#[When('dev')]
#[AsCommand(name: 'app:chaos:inject', description: 'Queue the four synthetic DLQ demo scenarios')]
final readonly class ChaosInjectCommand
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (null !== $this->tenantContext->tenantId()) {
            $io->error('Chaos scenarios can only be injected into the global demo scope.');

            return Command::FAILURE;
        }

        foreach ([
            new ChaosLowMessage(sprintf('chaos-low-%s', bin2hex(random_bytes(6)))),
            new ChaosMediumMessage(sprintf('chaos-medium-%s', bin2hex(random_bytes(6)))),
            new ChaosHighMessage(sprintf('chaos-high-%s', bin2hex(random_bytes(6)))),
            new ChaosUnclassifiedMessage(sprintf('chaos-unclassified-%s', bin2hex(random_bytes(6)))),
        ] as $message) {
            $this->messageBus->dispatch(
                $message,
                [new GlobalTenantStamp()],
            );
        }

        $io->success('Queued low, medium, high, and unclassified chaos scenarios.');
        $io->writeln('Each message fails four attempts before entering the failure transport. Wait for retries before running app:dlq:watch --once.');

        return Command::SUCCESS;
    }
}
