<?php

declare(strict_types=1);

namespace App\Tests\Integration\DlqDoctor\Service;

use App\DlqDoctor\Service\IdempotencyManager;
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
        $this->manager = new IdempotencyManager($this->connection);
        $this->key = bin2hex(random_bytes(16));
    }

    public function testAClaimIsUniqueWithinItsTenant(): void
    {
        self::assertTrue($this->manager->claim('tenant-a', $this->key, 'replay'));
        self::assertFalse($this->manager->claim('tenant-a', $this->key, 'replay'));
    }

    public function testTheSameKeyCanBeUsedByAnotherTenant(): void
    {
        self::assertTrue($this->manager->claim('tenant-a', $this->key, 'replay'));
        self::assertTrue($this->manager->claim('tenant-b', $this->key, 'replay'));
    }

    public function testAClaimCanBeCompletedWithAResult(): void
    {
        $this->manager->claim('tenant-a', $this->key, 'replay');
        $this->manager->complete('tenant-a', $this->key, ['messageId' => '42']);

        self::assertSame(['messageId' => '42'], $this->manager->result('tenant-a', $this->key));
    }
}
