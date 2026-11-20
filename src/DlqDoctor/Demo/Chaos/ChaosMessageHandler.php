<?php

declare(strict_types=1);

namespace App\DlqDoctor\Demo\Chaos;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[When('dev')]
final class ChaosMessageHandler
{
    private const FAILURE_ATTEMPTS = 4;

    #[AsMessageHandler]
    public function handleLow(ChaosLowMessage $message): void
    {
        if ('ready' !== $message->status) {
            throw new RuntimeException(sprintf('The synthetic low-risk message %s is not ready.', $message->reference));
        }
    }

    #[AsMessageHandler]
    public function handleMedium(ChaosMediumMessage $message): void
    {
        $this->failUntilRetriesExhausted($message);
    }

    #[AsMessageHandler]
    public function handleHigh(ChaosHighMessage $message): void
    {
        $this->failUntilRetriesExhausted($message);
    }

    #[AsMessageHandler]
    public function handleUnclassified(ChaosUnclassifiedMessage $message): void
    {
        $this->failUntilRetriesExhausted($message);
    }

    private function failUntilRetriesExhausted(ChaosMessage $message): void
    {
        ++$message->attempts;

        if ($message->attempts <= self::FAILURE_ATTEMPTS) {
            throw new RuntimeException(sprintf(
                'Synthetic chaos failure for %s (attempt %d).',
                $message->reference,
                $message->attempts,
            ));
        }
    }
}
