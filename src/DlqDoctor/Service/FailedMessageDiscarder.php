<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Enum\DlqAction;
use App\DlqDoctor\Enum\DlqDiscardOutcome;
use App\DlqDoctor\Enum\PolicyDecision;
use App\DlqDoctor\Context\TenantContextInterface;
use App\DlqDoctor\Messenger\TenantScope;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use UnexpectedValueException;

final readonly class FailedMessageDiscarder
{
    public function __construct(
        private FailedMessageTransport $failedTransport,
        private PolicyEvaluator $policyEvaluator,
        private SensitivityResolver $sensitivityResolver,
        private TenantContextInterface $tenantContext,
        private Security $security,
        private IdempotencyStore $idempotencyStore,
        private AuditLoggerInterface $auditLogger,
    ) {
    }

    public function discard(string $messageId): DlqDiscardOutcome
    {
        $this->denyUnlessAuthorized();

        if ('' === trim($messageId) || strlen($messageId) > 128) {
            throw new InvalidArgumentException('A message ID must contain between 1 and 128 characters.');
        }

        $tenantId = $this->tenantContext->tenantId();
        $envelope = $this->failedTransport->find($messageId);
        if (null === $envelope || !TenantScope::matches($envelope, $tenantId)) {
            throw new FailedMessageNotFoundException();
        }

        $transportMessageId = $envelope->last(TransportMessageIdStamp::class)?->getId();
        if ((string) $transportMessageId !== $messageId) {
            throw new UnexpectedValueException('The failed transport returned an envelope with a mismatched message ID.');
        }

        $sensitivity = $this->sensitivityResolver->resolve($envelope->getMessage()::class);
        $decision = $this->policyEvaluator->evaluate($sensitivity, DlqAction::DISCARD);
        if (PolicyDecision::AUTO !== $decision && PolicyDecision::AUTO_AUDIT !== $decision) {
            throw new AccessDeniedException(
                PolicyDecision::FORBIDDEN === $decision
                    ? 'The current policy forbids discarding this message.'
                    : 'Discarding this message requires a separate approval.',
            );
        }

        TenantScope::requireTenantId($this->tenantContext);
        $idempotencyKey = hash('sha256', DlqAction::DISCARD->value."\0".$messageId);
        if (!$this->idempotencyStore->claim($idempotencyKey, DlqAction::DISCARD->value)) {
            $result = $this->idempotencyStore->result($idempotencyKey);
            if (null === $result) {
                throw new RuntimeException('This discard operation is already in progress.');
            }

            if (DlqDiscardOutcome::DISCARDED->value !== ($result['outcome'] ?? null)) {
                throw new UnexpectedValueException('The completed DLQ discard result has an unknown outcome.');
            }

            $this->failedTransport->acknowledge($envelope);

            return DlqDiscardOutcome::ALREADY_DISCARDED;
        }

        $this->auditLogger->log(
            'discard_requested',
            ['sensitivity' => $sensitivity->value],
            $messageId,
        );
        $this->failedTransport->acknowledge($envelope);
        $this->auditLogger->log(
            'discarded',
            ['sensitivity' => $sensitivity->value],
            $messageId,
        );
        $this->idempotencyStore->complete($idempotencyKey, [
            'outcome' => DlqDiscardOutcome::DISCARDED->value,
        ]);

        return DlqDiscardOutcome::DISCARDED;
    }

    private function denyUnlessAuthorized(): void
    {
        if (!$this->security->isGranted('ROLE_DLQ_OPERATOR')) {
            throw new AccessDeniedException('The current user cannot discard failed messages.');
        }
    }
}
