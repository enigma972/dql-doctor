<?php

declare(strict_types=1);

namespace App\Tests\Integration\DlqDoctor\Service;

use App\DlqDoctor\Service\AuditLogger;
use App\Tests\Fixtures\DlqDoctor\FixedTenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AuditLoggerTest extends KernelTestCase
{
    public function testItPersistsTenantScopedAuditDetails(): void
    {
        $tenantId = bin2hex(random_bytes(16));
        $logger = new AuditLogger(
            static::getContainer()->get(EntityManagerInterface::class),
            new FixedTenantContext($tenantId, 'actor-1'),
        );

        $logger->log('message_replayed', ['messageId' => '42'], '42');

        $connection = static::getContainer()->get(Connection::class);
        $audit = $connection->fetchAssociative(
            'SELECT tenant_id, action, actor_id, failed_message_id, context FROM dlq_audit_log WHERE tenant_id = ?',
            [$tenantId],
        );

        self::assertIsArray($audit);
        self::assertSame($tenantId, $audit['tenant_id']);
        self::assertSame('message_replayed', $audit['action']);
        self::assertSame('actor-1', $audit['actor_id']);
        self::assertSame('42', $audit['failed_message_id']);
        self::assertSame(['messageId' => '42'], json_decode($audit['context'], true, flags: JSON_THROW_ON_ERROR));
    }
}
