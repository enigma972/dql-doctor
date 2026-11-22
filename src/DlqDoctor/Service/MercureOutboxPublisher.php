<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use UnexpectedValueException;

final readonly class MercureOutboxPublisher
{
    public function __construct(
        private Connection $connection,
        private HubInterface $hub,
    ) {
    }

    public function publishPending(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('The outbox publish limit must be between 1 and 1000.');
        }

        $published = 0;
        while ($published < $limit) {
            $didPublish = $this->connection->transactional(function (): bool {
                $message = $this->connection->fetchAssociative(
                    <<<'SQL'
                        SELECT id, topic, event_type, data
                        FROM dlq_mercure_outbox
                        WHERE published_at IS NULL
                        ORDER BY id
                        LIMIT 1
                        FOR UPDATE SKIP LOCKED
                        SQL,
                );

                if (false === $message) {
                    return false;
                }

                if (!is_string($message['topic']) || !is_string($message['event_type']) || !is_string($message['data'])) {
                    throw new UnexpectedValueException('The Mercure outbox row has invalid event data.');
                }

                $this->hub->publish(new Update(
                    $message['topic'],
                    $message['data'],
                    true,
                    type: $message['event_type'],
                ));

                $affectedRows = $this->connection->executeStatement(
                    'UPDATE dlq_mercure_outbox SET published_at = ? WHERE id = ? AND published_at IS NULL',
                    [new DateTimeImmutable('now', new DateTimeZone('UTC')), $message['id']],
                    [Types::DATETIME_IMMUTABLE, Types::INTEGER],
                );

                if (1 !== $affectedRows) {
                    throw new UnexpectedValueException('The Mercure outbox event could not be marked as published.');
                }

                return true;
            });

            if (!$didPublish) {
                break;
            }

            ++$published;
        }

        return $published;
    }
}
