<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Service;

use App\DlqDoctor\Service\AuditLogger;
use App\Tests\Fixtures\DlqDoctor\FixedTenantContext;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase
{
    public function testItRejectsAnOversizedContextBeforeWriting(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $logger = new AuditLogger($entityManager, new FixedTenantContext('tenant-a', 'actor-a'));

        $this->expectException(InvalidArgumentException::class);
        $logger->log('message_replayed', ['details' => str_repeat('x', 5000)]);
    }

    public function testItRejectsNonJsonAuditContextBeforeWriting(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $logger = new AuditLogger($entityManager, new FixedTenantContext('tenant-a', 'actor-a'));

        $this->expectException(InvalidArgumentException::class);
        $logger->log('message_replayed', ['number' => INF]);
    }
}
