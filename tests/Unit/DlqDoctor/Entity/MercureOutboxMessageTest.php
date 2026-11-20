<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Entity;

use App\DlqDoctor\Entity\MercureOutboxMessage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MercureOutboxMessageTest extends TestCase
{
    public function testItRejectsEventPayloadsLargerThanTheConfiguredBound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Outbox data cannot exceed 4096 bytes.');

        new MercureOutboxMessage(null, 'http://localhost/dlq-doctor/proposals', 'proposal.updated', [
            'status' => str_repeat('x', 4096),
        ]);
    }
}
