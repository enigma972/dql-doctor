<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;

final readonly class DoctrineFailedMessageTransport implements FailedMessageTransport
{
    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')]
        private ListableReceiverInterface $receiver,
    ) {
    }

    public function all(?int $limit = null): iterable
    {
        return $this->receiver->all($limit);
    }

    public function find(string $messageId): ?Envelope
    {
        return $this->receiver->find($messageId);
    }

    public function acknowledge(Envelope $envelope): void
    {
        $this->receiver->ack($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->receiver->reject($envelope);
    }
}
