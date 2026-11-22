<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use Symfony\Component\Messenger\Envelope;

interface FailedMessageTransport extends FailedMessageReader
{
    /**
     * @return iterable<Envelope>
     */
    public function all(?int $limit = null): iterable;

    public function acknowledge(Envelope $envelope): void;

    public function reject(Envelope $envelope): void;
}
