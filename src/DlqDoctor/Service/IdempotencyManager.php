<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use LogicException;
use UnexpectedValueException;

final readonly class IdempotencyManager implements IdempotencyStore
{
    public function __construct(private Connection $connection)
    {
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new LogicException('Idempotency claims currently require PostgreSQL.');
        }
    }

    public function claim(string $tenantId, string $key, string $action): bool
    {
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO dlq_idempotency_record
                    (tenant_id, idempotency_key, action, status, result, created_at, completed_at)
                VALUES (?, ?, ?, 'started', NULL, ?, NULL)
                ON CONFLICT (tenant_id, idempotency_key) DO NOTHING
                SQL,
            [$tenantId, $key, $action, new DateTimeImmutable('now', new DateTimeZone('UTC'))],
            [Types::STRING, Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE],
        );

        return 1 === $affectedRows;
    }

    public function complete(string $tenantId, string $key, array $result): void
    {
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE dlq_idempotency_record
                SET status = 'completed', result = ?, completed_at = ?
                WHERE tenant_id = ? AND idempotency_key = ? AND status = 'started'
                SQL,
            [
                $result,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
                $tenantId,
                $key,
            ],
            [Types::JSON, Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING],
        );

        if (1 !== $affectedRows) {
            throw new LogicException('Cannot complete an idempotency key that is not in the started state.');
        }
    }

    public function result(string $tenantId, string $key): ?array
    {
        $result = $this->connection->fetchOne(
            "SELECT result FROM dlq_idempotency_record WHERE tenant_id = ? AND idempotency_key = ? AND status = 'completed'",
            [$tenantId, $key],
            [Types::STRING, Types::STRING],
        );

        if (false === $result || null === $result) {
            return null;
        }

        $decoded = json_decode((string) $result, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new UnexpectedValueException('The stored idempotency result is not a JSON object or array.');
        }

        return $decoded;
    }
}
