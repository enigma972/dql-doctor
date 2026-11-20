<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Entity\RepairProposal;
use App\DlqDoctor\Enum\DlqAction;
use App\DlqDoctor\Enum\DlqProcessingOutcome;
use App\DlqDoctor\Enum\PolicyDecision;
use App\DlqDoctor\Messenger\TenantContext;
use App\DlqDoctor\Messenger\TenantStamp;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use UnexpectedValueException;

final readonly class FailedMessageProcessor
{
    private const MAX_PROPOSAL_PAYLOAD_BYTES = 65_536;

    public function __construct(
        private FailedMessageTransport $failedTransport,
        #[Autowire(service: 'messenger.transport.async')]
        private SenderInterface $asyncSender,
        private IdempotencyStore $idempotencyStore,
        private AuditLoggerInterface $auditLogger,
        private PolicyEvaluator $policyEvaluator,
        private SensitivityResolver $sensitivityResolver,
        private NormalizerInterface $normalizer,
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private ProposalEventRecorder $proposalEvents,
    ) {
    }

    public function process(Envelope $envelope): DlqProcessingOutcome
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId !== $envelope->last(TenantStamp::class)?->tenantId) {
            return DlqProcessingOutcome::OTHER_TENANT;
        }

        $messageId = $envelope->last(TransportMessageIdStamp::class)?->getId();
        if (!is_string($messageId) && !is_int($messageId)) {
            throw new UnexpectedValueException('The failed transport returned an envelope without a valid message ID.');
        }
        $messageId = (string) $messageId;

        $message = $envelope->getMessage();
        $sensitivity = $this->sensitivityResolver->resolve($message::class);
        $decision = $this->policyEvaluator->evaluate($sensitivity, DlqAction::REPLAY);

        if (PolicyDecision::FORBIDDEN === $decision) {
            return DlqProcessingOutcome::FORBIDDEN;
        }

        $payload = null;
        if (PolicyDecision::APPROVAL === $decision) {
            $normalizedPayload = $this->normalizer->normalize($message);
            if (!is_array($normalizedPayload)) {
                throw new UnexpectedValueException('The failed message did not normalize to an object payload.');
            }

            $jsonPayload = json_encode($normalizedPayload, JSON_THROW_ON_ERROR);
            if (strlen($jsonPayload) > self::MAX_PROPOSAL_PAYLOAD_BYTES) {
                throw new UnexpectedValueException('The normalized failed-message payload exceeds the 65536-byte proposal limit.');
            }

            $payload = json_decode($jsonPayload, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new UnexpectedValueException('The normalized failed-message payload is not a JSON object or array.');
            }
        }

        $idempotencyKey = hash('sha256', DlqAction::REPLAY->value."\0".$messageId);
        if (PolicyDecision::APPROVAL === $decision) {
            if (null === $payload) {
                throw new LogicException('An approval proposal requires a normalized message payload.');
            }

            return $this->createApprovalProposal($envelope, $tenantId, $messageId, $message::class, $payload, $sensitivity->value, $idempotencyKey);
        }

        if (!$this->idempotencyStore->claim($idempotencyKey, DlqAction::REPLAY->value)) {
            $result = $this->idempotencyStore->result($idempotencyKey);
            if (null === $result) {
                return DlqProcessingOutcome::IN_PROGRESS;
            }

            $completedOutcome = $result['outcome'] ?? null;
            if (!in_array($completedOutcome, [
                DlqProcessingOutcome::REPLAYED->value,
                DlqProcessingOutcome::PROPOSAL_CREATED->value,
            ], true)) {
                throw new UnexpectedValueException('The completed DLQ idempotency result has an unknown outcome.');
            }

            if (DlqProcessingOutcome::REPLAYED->value === $completedOutcome) {
                $this->failedTransport->acknowledge($envelope);
            }

            return DlqProcessingOutcome::ALREADY_PROCESSED;
        }

        $replayEnvelope = $envelope
            ->withoutAll(ErrorDetailsStamp::class)
            ->withoutAll(RedeliveryStamp::class)
            ->withoutAll(SentToFailureTransportStamp::class)
            ->withoutAll(TransportMessageIdStamp::class);
        $this->asyncSender->send($replayEnvelope);

        if (PolicyDecision::AUTO_AUDIT === $decision) {
            $this->auditLogger->log(
                'replayed',
                ['sensitivity' => $sensitivity->value],
                $messageId,
            );
        }

        $this->idempotencyStore->complete($idempotencyKey, [
            'outcome' => DlqProcessingOutcome::REPLAYED->value,
        ]);
        $this->failedTransport->acknowledge($envelope);

        return DlqProcessingOutcome::REPLAYED;
    }

    /**
     * @param array<string|int, mixed> $payload
     */
    private function createApprovalProposal(
        Envelope $envelope,
        string $tenantId,
        string $messageId,
        string $messageClass,
        array $payload,
        string $sensitivity,
        string $idempotencyKey,
    ): DlqProcessingOutcome {
        return $this->entityManager->wrapInTransaction(function () use ($envelope, $tenantId, $messageId, $messageClass, $payload, $sensitivity, $idempotencyKey): DlqProcessingOutcome {
            if (!$this->idempotencyStore->claim($idempotencyKey, DlqAction::REPLAY->value)) {
                $result = $this->idempotencyStore->result($idempotencyKey);
                if (null === $result) {
                    return DlqProcessingOutcome::IN_PROGRESS;
                }

                if (DlqProcessingOutcome::PROPOSAL_CREATED->value !== ($result['outcome'] ?? null)) {
                    throw new UnexpectedValueException('The completed DLQ approval idempotency result has an unknown outcome.');
                }

                return DlqProcessingOutcome::ALREADY_PROCESSED;
            }

            $proposal = new RepairProposal(
                $tenantId,
                $messageId,
                $messageClass,
                $payload,
                null,
                null,
                null,
                $idempotencyKey,
                new DateTimeImmutable('+1 day', new DateTimeZone('UTC')),
            );
            $this->entityManager->persist($proposal);
            $this->proposalEvents->record($proposal);
            $this->idempotencyStore->complete($idempotencyKey, [
                'outcome' => DlqProcessingOutcome::PROPOSAL_CREATED->value,
            ]);
            $this->auditLogger->log(
                'approval_required',
                ['sensitivity' => $sensitivity],
                $messageId,
            );
            return DlqProcessingOutcome::PROPOSAL_CREATED;
        });
    }
}
