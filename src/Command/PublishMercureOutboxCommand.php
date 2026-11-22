<?php

declare(strict_types=1);

namespace App\Command;

use App\DlqDoctor\Service\MercureOutboxPublisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dlq:publish-outbox', description: 'Publish committed DLQ events to Mercure')]
final class PublishMercureOutboxCommand extends Command
{
    public function __construct(
        private readonly MercureOutboxPublisher $publisher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('loop', null, InputOption::VALUE_NONE, 'Poll the outbox continuously');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        do {
            $published = $this->publisher->publishPending();
            if (0 < $published) {
                $output->writeln(sprintf('Published %d DLQ event(s) to Mercure.', $published));
            }

            if (!$input->getOption('loop')) {
                break;
            }

            if (0 === $published) {
                sleep(1);
            }
        } while (true);

        return Command::SUCCESS;
    }
}
