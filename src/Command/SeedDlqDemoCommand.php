<?php

declare(strict_types=1);

namespace App\Command;

use App\DlqDoctor\Context\TenantContextInterface;
use App\DlqDoctor\Demo\DemoMessage;
use App\DlqDoctor\Messenger\GlobalTenantStamp;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

#[When('dev')]
#[AsCommand(name: 'app:dlq:demo:seed', description: 'Queue a synthetic failure for the local global demo')]
final readonly class SeedDlqDemoCommand
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        #[Autowire(service: 'messenger.transport.failed')]
        private SenderInterface $failedTransport,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (null !== $this->tenantContext->tenantId()) {
            $io->error('The demo seed command requires the global scope.');

            return Command::FAILURE;
        }

        $envelope = $this->failedTransport->send(new Envelope(
            new DemoMessage('demo-'.bin2hex(random_bytes(8))),
            [new GlobalTenantStamp()],
        ));
        $id = $envelope->last(TransportMessageIdStamp::class)?->getId()
            ?? throw new \LogicException('The failure transport did not return a message ID.');
        $io->success(sprintf('Queued global demo failure %s.', $id));

        return Command::SUCCESS;
    }
}
