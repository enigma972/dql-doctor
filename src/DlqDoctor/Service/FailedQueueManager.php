<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Dto\FailedMessageSummary;
use App\DlqDoctor\Context\TenantContextInterface;
use App\DlqDoctor\Messenger\TenantScope;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use UnexpectedValueException;

final readonly class FailedQueueManager
{
    public function __construct(
        private FailedMessageTransport $receiver,
        private SensitivityResolver $sensitivityResolver,
        private TenantContextInterface $tenantContext,
        private Security $security,
    ) {
    }

    /**
     * @return list<FailedMessageSummary>
     */
    public function listForCurrentTenant(int $scanLimit = 100): array
    {
        if ($scanLimit < 1 || $scanLimit > 500) {
            throw new InvalidArgumentException('The failure scan limit must be between 1 and 500.');
        }

        $this->denyUnlessAuthorized();
        $tenantId = $this->tenantContext->tenantId();
        $failures = [];

        foreach ($this->receiver->all($scanLimit) as $envelope) {
            if (!TenantScope::matches($envelope, $tenantId)) {
                continue;
            }

            $failures[] = $this->summarize($envelope);
        }

        return $failures;
    }

    public function findSummaryForCurrentTenant(string $messageId): ?FailedMessageSummary
    {
        if ('' === trim($messageId) || strlen($messageId) > 128) {
            throw new InvalidArgumentException('A message ID must contain between 1 and 128 characters.');
        }

        $this->denyUnlessAuthorized();
        $tenantId = $this->tenantContext->tenantId();
        $envelope = $this->receiver->find($messageId);

        if (null === $envelope || !TenantScope::matches($envelope, $tenantId)) {
            return null;
        }

        return $this->summarize($envelope);
    }

    private function denyUnlessAuthorized(): void
    {
        if (!$this->security->isGranted('ROLE_DLQ_VIEWER')) {
            throw new AccessDeniedException('The current user cannot view failed messages.');
        }
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
