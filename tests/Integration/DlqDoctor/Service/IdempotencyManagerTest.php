<?php

declare(strict_types=1);

namespace App\Tests\Integration\DlqDoctor\Service;

use App\DlqDoctor\Service\IdempotencyManager;
use App\Tests\Fixtures\DlqDoctor\FixedTenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IdempotencyManagerTest extends KernelTestCase
{
    private Connection $connection;
    private IdempotencyManager $manager;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = static::getContainer()->get(Connection::class);
        $this->manager = new IdempotencyManager($this->connection, new FixedTenantContext('tenant-a'));
        $this->key = bin2hex(random_bytes(16));
    }

    public function testAClaimIsUniqueWithinItsTenant(): void
    {
        self::assertTrue($this->manager->claim($this->key, 'replay'));
        self::assertFalse($this->manager->claim($this->key, 'replay'));
    }

    public function testTheSameKeyCanBeUsedByAnotherTenant(): void
    {
        self::assertTrue($this->manager->claim($this->key, 'replay'));
        self::assertTrue((new IdempotencyManager($this->connection, new FixedTenantContext('tenant-b')))->claim($this->key, 'replay'));
    }

    public function testAClaimCanBeCompletedWithAResult(): void
    {
        $this->manager->claim($this->key, 'replay');
        $this->manager->complete($this->key, ['messageId' => '42']);

        self::assertSame(['messageId' => '42'], $this->manager->result($this->key));
        self::assertNull((new IdempotencyManager($this->connection, new FixedTenantContext('tenant-b')))->result($this->key));
    }
}
