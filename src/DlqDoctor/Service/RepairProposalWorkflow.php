<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Agent\JsonObjectPayload;
use App\DlqDoctor\Dto\RepairProposalReview;
use App\DlqDoctor\Entity\RepairProposal;
use App\DlqDoctor\Enum\DlqAction;
use App\DlqDoctor\Enum\PolicyDecision;
use App\DlqDoctor\Enum\RepairProposalStatus;
use App\DlqDoctor\Messenger\TenantContext;
use App\DlqDoctor\Messenger\TenantStamp;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use InvalidArgumentException;
use LogicException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use TypeError;
use UnexpectedValueException;

final readonly class RepairProposalWorkflow
{
    private const MAX_PAYLOAD_BYTES = 65_536;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TenantContext $tenantContext,
        private Security $security,
        private IdempotencyStore $idempotencyStore,
        private AuditLoggerInterface $auditLogger,
        private ProposalEventRecorder $proposalEvents,
        private PolicyEvaluator $policyEvaluator,
        private SensitivityResolver $sensitivityResolver,
        private FailedMessageTransport $failedTransport,
        private NormalizerInterface $normalizer,
        private DenormalizerInterface $denormalizer,
        private ValidatorInterface $validator,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire(service: 'messenger.transport.async')]
        private SenderInterface $asyncSender,
    ) {
    }

    public function review(int $id): ?RepairProposalReview
    {
        $this->denyUnlessOperator();

        return $this->entityManager->wrapInTransaction(function () use ($id): ?RepairProposalReview {
            $proposal = $this->lockForCurrentTenant($id);
            if (null === $proposal) {
                return null;
            }

            $now = $this->now();
            if ($proposal->expireIfDue($now)) {
                $this->recordStateChange($proposal, 'proposal_expired');
            }

            $token = RepairProposalStatus::PENDING_APPROVAL === $proposal->status()
                ? $proposal->issueApprovalToken($now)
                : null;

            return new RepairProposalReview(
                $proposal->summary(),
                $proposal->originalPayload(),
                $proposal->patchedPayload(),
                $proposal->diff(),
                $token,
            );
        });
    }

    public function approve(int $id, string $token): RepairProposalStatus
    {
        $this->denyUnlessOperator();

        $result = $this->entityManager->wrapInTransaction(function () use ($id, $token): RepairProposalStatus|Envelope|DomainException|InvalidArgumentException|FailedMessageNotFoundException {
            $proposal = $this->lockForCurrentTenant($id);
            if (null === $proposal) {
                return new FailedMessageNotFoundException();
            }

            try {
                $now = $this->now();
                if ($proposal->expireIfDue($now)) {
                    $this->recordStateChange($proposal, 'proposal_expired');

                    return RepairProposalStatus::EXPIRED;
                }

                if (RepairProposalStatus::PENDING_APPROVAL !== $proposal->status()) {
                    throw new DomainException('Only a pending proposal can be approved.');
                }

                $proposal->assertApprovalToken($token, $now);
                $envelope = $this->loadFailedEnvelope($proposal);
                $message = $envelope->getMessage();
                $currentPayload = $this->normalizer->normalize($message);
                if (!is_array($currentPayload)
                    || $this->canonicalJson($currentPayload) !== $this->canonicalJson($proposal->originalPayload())
                ) {
                    throw new InvalidArgumentException('The failed message payload no longer matches the proposal.');
                }

                if (count($this->validator->validate($message)) > 0) {
                    throw new InvalidArgumentException('The failed message no longer satisfies message validation.');
                }

                $this->assertReplayAllowed($proposal->messageClass());
                $patchedPayload = $proposal->patchedPayload();
                if (null !== $patchedPayload) {
                    if (null === $proposal->diff()) {
                        throw new InvalidArgumentException('A patched proposal must include a stored diff.');
                    }

                    $this->assertPolicyAllows($proposal->messageClass(), DlqAction::PATCH);
                    $message = $this->restoreMessage($patchedPayload, $proposal->messageClass());
                    $envelope = $envelope->with($message);
                }

                $approvalKey = $this->approvalIdempotencyKey($proposal);
                if (!$this->idempotencyStore->claim($approvalKey, 'approve_replay')) {
                    throw new DomainException('This proposal replay is already in progress or has already completed.');
                }

                $proposal->approve($token, $now);
                $this->recordStateChange($proposal, 'proposal_approved');

                return $envelope
                    ->withoutAll(ErrorDetailsStamp::class)
                    ->withoutAll(RedeliveryStamp::class)
                    ->withoutAll(SentToFailureTransportStamp::class)
                    ->withoutAll(TransportMessageIdStamp::class);
            } catch (DomainException|InvalidArgumentException|FailedMessageNotFoundException $exception) {
                return $exception;
            }
        });

        if ($result instanceof DomainException || $result instanceof InvalidArgumentException || $result instanceof FailedMessageNotFoundException) {
            throw $result;
        }

        if ($result instanceof RepairProposalStatus) {
            return $result;
        }

        $this->asyncSender->send($result);
        $this->entityManager->wrapInTransaction(function () use ($id): void {
            $proposal = $this->requireProposal($id);
            if (RepairProposalStatus::APPROVED !== $proposal->status()) {
                throw new LogicException('A proposal changed state while its replay was being sent.');
            }

            $this->failedTransport->acknowledge($this->loadFailedEnvelope($proposal));
            $proposal->markReplayed();
            $this->idempotencyStore->complete($this->approvalIdempotencyKey($proposal), [
                'outcome' => 'replayed',
            ]);
            $this->auditLogger->log('proposal_replayed', [], $proposal->failedMessageId());
            $this->proposalEvents->record($proposal);
        });

        return RepairProposalStatus::REPLAYED;
    }

    public function reject(int $id, string $token): RepairProposalStatus
    {
        $this->denyUnlessOperator();

        $result = $this->entityManager->wrapInTransaction(function () use ($id, $token): RepairProposalStatus|DomainException|FailedMessageNotFoundException {
            $proposal = $this->lockForCurrentTenant($id);
            if (null === $proposal) {
                return new FailedMessageNotFoundException();
            }

            $now = $this->now();
            if ($proposal->expireIfDue($now)) {
                $this->recordStateChange($proposal, 'proposal_expired');

                return RepairProposalStatus::EXPIRED;
            }

            try {
                $proposal->reject($token, $now);
                $this->recordStateChange($proposal, 'proposal_rejected');
            } catch (DomainException $exception) {
                return $exception;
            }

            return RepairProposalStatus::REJECTED;
        });

        if ($result instanceof DomainException || $result instanceof FailedMessageNotFoundException) {
            throw $result;
        }

        return $result;
    }

    private function lockForCurrentTenant(int $id): ?RepairProposal
    {
        if ($id < 1) {
            throw new InvalidArgumentException('A positive proposal ID is required.');
        }

        $proposal = $this->entityManager->getRepository(RepairProposal::class)->findOneBy([
            'id' => $id,
            'tenantId' => $this->tenantContext->tenantId(),
        ]);
        if (!$proposal instanceof RepairProposal) {
            return null;
        }

        $this->entityManager->lock($proposal, LockMode::PESSIMISTIC_WRITE);

        return $proposal;
    }

    private function requireProposal(int $id): RepairProposal
    {
        return $this->lockForCurrentTenant($id) ?? throw new FailedMessageNotFoundException();
    }

    private function loadFailedEnvelope(RepairProposal $proposal): Envelope
    {
        $envelope = $this->failedTransport->find($proposal->failedMessageId());
        if (null === $envelope
            || $proposal->tenantId() !== $envelope->last(TenantStamp::class)?->tenantId
            || $proposal->messageClass() !== $envelope->getMessage()::class
            || (string) $envelope->last(TransportMessageIdStamp::class)?->getId() !== $proposal->failedMessageId()
        ) {
            throw new FailedMessageNotFoundException();
        }

        return $envelope;
    }

    private function restoreMessage(array $payload, string $messageClass): object
    {
        if (!class_exists($messageClass)) {
            throw new UnexpectedValueException('The proposal message class is no longer available.');
        }

        JsonObjectPayload::encode($payload, 'A proposal payload', self::MAX_PAYLOAD_BYTES);

        try {
            $message = $this->denormalizer->denormalize(
                $payload,
                $messageClass,
                'json',
                [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
            );
        } catch (ExceptionInterface|TypeError $exception) {
            throw new InvalidArgumentException('The proposal payload can no longer be restored.', previous: $exception);
        }

        if (!is_object($message) || $messageClass !== $message::class) {
            throw new InvalidArgumentException('The proposal payload restored to an unexpected message type.');
        }

        if (count($this->validator->validate($message)) > 0) {
            throw new InvalidArgumentException('The proposal payload no longer satisfies message validation.');
        }

        return $message;
    }

    private function assertReplayAllowed(string $messageClass): void
    {
        $this->assertPolicyAllows($messageClass, DlqAction::REPLAY);
    }

    private function assertPolicyAllows(string $messageClass, DlqAction $action): void
    {
        if (PolicyDecision::FORBIDDEN === $this->policyEvaluator->evaluate(
            $this->sensitivityResolver->resolve($messageClass),
            $action,
        )) {
            throw new DomainException(sprintf('The current policy forbids the proposal %s action.', $action->value));
        }
    }

    private function recordStateChange(RepairProposal $proposal, string $action): void
    {
        $this->auditLogger->log($action, ['status' => $proposal->status()->value], $proposal->failedMessageId());
        $this->proposalEvents->record($proposal);
    }

    private function approvalIdempotencyKey(RepairProposal $proposal): string
    {
        return hash('sha256', "approve_replay\0".$proposal->idempotencyKey());
    }

    /**
     * @param array<string|int, mixed> $payload
     */
    private function canonicalJson(array $payload): string
    {
        $canonicalize = function (array $value) use (&$canonicalize): array {
            if (!array_is_list($value)) {
                ksort($value);
            }

            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $canonicalize($item);
                }
            }

            return $value;
        };

        return json_encode($canonicalize($payload), JSON_THROW_ON_ERROR, 64);
    }

    private function denyUnlessOperator(): void
    {
        if (!$this->security->isGranted('ROLE_DLQ_OPERATOR')) {
            throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('The current user cannot review repair proposals.');
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
