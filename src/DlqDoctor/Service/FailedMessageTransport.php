<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use Symfony\Component\Messenger\Envelope;

interface FailedMessageTransport
{
    /**
     * @return iterable<Envelope>
     */
    public function all(?int $limit = null): iterable;

    public function find(string $messageId): ?Envelope;

    public function acknowledge(Envelope $envelope): void;

    public function reject(Envelope $envelope): void;
}
