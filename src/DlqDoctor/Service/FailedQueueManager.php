<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Dto\FailedMessageSummary;
use App\DlqDoctor\Messenger\TenantStamp;
use InvalidArgumentException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use UnexpectedValueException;

final readonly class FailedQueueManager
{
    public function __construct(
        private FailedMessageTransport $receiver,
        private SensitivityResolver $sensitivityResolver,
    ) {
    }

    /**
     * @return list<FailedMessageSummary>
     */
    public function listForTenant(string $tenantId, int $scanLimit = 100): array
    {
        if ('' === trim($tenantId)) {
            throw new InvalidArgumentException('A tenant ID is required.');
        }

        if ($scanLimit < 1 || $scanLimit > 500) {
            throw new InvalidArgumentException('The failure scan limit must be between 1 and 500.');
        }

        $failures = [];

        foreach ($this->receiver->all($scanLimit) as $envelope) {
            if (!$this->belongsToTenant($envelope, $tenantId)) {
                continue;
            }

            $failures[] = $this->summarize($envelope);
        }

        return $failures;
    }

    public function findForTenant(string $messageId, string $tenantId): ?Envelope
    {
        if ('' === trim($tenantId)) {
            throw new InvalidArgumentException('A tenant ID is required.');
        }

        $envelope = $this->receiver->find($messageId);

        if (null === $envelope || !$this->belongsToTenant($envelope, $tenantId)) {
            return null;
        }

        return $envelope;
    }

    private function belongsToTenant(Envelope $envelope, string $tenantId): bool
    {
        return $tenantId === $envelope->last(TenantStamp::class)?->tenantId;
    }

    private function summarize(Envelope $envelope): FailedMessageSummary
    {
        $messageId = $envelope->last(TransportMessageIdStamp::class)?->getId();

        if (!is_string($messageId) && !is_int($messageId)) {
            throw new UnexpectedValueException('The failed transport returned an envelope without a valid message ID.');
        }

        $message = $envelope->getMessage();

        return new FailedMessageSummary(
            (string) $messageId,
            $message::class,
            $envelope->last(ErrorDetailsStamp::class)?->getExceptionClass(),
            $this->sensitivityResolver->resolve($message::class),
        );
    }
}
