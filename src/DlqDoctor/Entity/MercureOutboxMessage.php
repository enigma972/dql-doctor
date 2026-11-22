<?php

declare(strict_types=1);

namespace App\DlqDoctor\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use JsonException;

#[ORM\Entity]
#[ORM\Table(name: 'dlq_mercure_outbox')]
#[ORM\Index(name: 'idx_dlq_mercure_outbox_pending', columns: ['published_at', 'id'])]
final class MercureOutboxMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $tenantId;

    #[ORM\Column(length: 512)]
    private string $topic;

    #[ORM\Column(length: 64)]
    private string $eventType;

    #[ORM\Column(type: 'json')]
    private array $data;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(?string $tenantId, string $topic, string $eventType, array $data)
    {
        if (null !== $tenantId && ('' === trim($tenantId) || strlen($tenantId) > 128)) {
            throw new InvalidArgumentException('An outbox tenant ID must contain between 1 and 128 characters.');
        }

        if ('' === trim($topic) || strlen($topic) > 512) {
            throw new InvalidArgumentException('An outbox topic must contain between 1 and 512 characters.');
        }

        if ('' === trim($eventType) || strlen($eventType) > 64) {
            throw new InvalidArgumentException('An outbox event type must contain between 1 and 64 characters.');
        }

        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Outbox data must be JSON serializable.', previous: $exception);
        }

        if (strlen($encoded) > 4096) {
            throw new InvalidArgumentException('Outbox data cannot exceed 4096 bytes.');
        }

        $this->tenantId = $tenantId;
        $this->topic = $topic;
        $this->eventType = $eventType;
        $this->data = $data;
        $this->createdAt = new DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function topic(): string
    {
        return $this->topic;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function markPublished(DateTimeImmutable $publishedAt): void
    {
        if (null !== $this->publishedAt) {
            throw new \LogicException('An outbox message can only be marked published once.');
        }

        $this->publishedAt = $publishedAt;
    }
}
