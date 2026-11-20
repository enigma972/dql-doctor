<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use Symfony\Component\Messenger\Envelope;

interface FailedMessageReader
{
    public function find(string $messageId): ?Envelope;
}
