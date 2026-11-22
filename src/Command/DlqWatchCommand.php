<?php

declare(strict_types=1);

namespace App\Command;

use App\DlqDoctor\Service\DlqWatchService;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:dlq:watch', description: 'Process failed messages according to the DLQ policy')]
final class DlqWatchCommand extends Command
{
    public function __construct(
        private readonly DlqWatchService $watchService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process one bounded batch and exit')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of failure envelopes scanned per batch', 100)
            ->addOption('poll-interval', null, InputOption::VALUE_REQUIRED, 'Seconds to wait between batches when watching continuously', 5)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        $pollInterval = filter_var($input->getOption('poll-interval'), FILTER_VALIDATE_INT);

        if (false === $limit || $limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('The --limit option must be between 1 and 500.');
        }

        if (false === $pollInterval || $pollInterval < 1) {
            throw new InvalidArgumentException('The --poll-interval option must be at least 1 second.');
        }

        $io = new SymfonyStyle($input, $output);
        do {
            $outcomes = $this->watchService->processBatch($limit);
            $io->writeln(sprintf(
                'DLQ batch: %d replayed, %d approval proposals, %d already processed, %d in progress, %d forbidden.',
                $outcomes['replayed'],
                $outcomes['proposal_created'],
                $outcomes['already_processed'],
                $outcomes['in_progress'],
                $outcomes['forbidden'],
            ));

            if ($input->getOption('once')) {
                break;
            }

            sleep($pollInterval);
        } while (true);

        return Command::SUCCESS;
    }
}
