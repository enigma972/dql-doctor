<?php

declare(strict_types=1);

namespace App\Mcp;

use App\DlqDoctor\Dto\FailedMessageSummary;
use App\DlqDoctor\Enum\DlqProcessingOutcome;
use App\DlqDoctor\Context\TenantContextInterface;
use App\DlqDoctor\Messenger\TenantScope;
use App\DlqDoctor\Service\ContractInspector;
use App\DlqDoctor\Service\FailedMessageDiscarder;
use App\DlqDoctor\Service\FailedMessageNotFoundException;
use App\DlqDoctor\Service\FailedMessageProcessor;
use App\DlqDoctor\Service\FailedMessageReader;
use App\DlqDoctor\Service\FailedQueueManager;
use App\DlqDoctor\Service\PatchDryRunService;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class DlqDoctorTools
{
    public function __construct(
        private FailedQueueManager $failedQueueManager,
        private ContractInspector $contractInspector,
        private PatchDryRunService $patchDryRunService,
        private FailedMessageReader $failedMessageReader,
        private FailedMessageProcessor $failedMessageProcessor,
        private FailedMessageDiscarder $failedMessageDiscarder,
        private TenantContextInterface $tenantContext,
        private Security $security,
    ) {
    }

    /**
     * @return array{failures: list<array{id: string, messageClass: string, errorClass: string|null, sensitivity: string}>}
     */
    #[McpTool(
        name: 'dlq_list_failures',
        description: 'List bounded summaries of failed messages belonging to the authenticated tenant.',
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'failures' => [
                    'type' => 'array',
                    'maxItems' => 500,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'messageClass' => ['type' => 'string'],
                            'errorClass' => ['type' => ['string', 'null']],
                            'sensitivity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                        ],
                        'required' => ['id', 'messageClass', 'errorClass', 'sensitivity'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['failures'],
            'additionalProperties' => false,
        ],
    )]
    #[Schema(definition: [
        'type' => 'object',
        'properties' => [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100],
        ],
        'additionalProperties' => false,
    ])]
    public function listFailures(int $limit = 100): array
    {
        $this->authorizeViewer();

        return $this->boundedResponse([
            'failures' => array_map(
                self::failureSummary(...),
                $this->failedQueueManager->listForCurrentTenant($limit),
            ),
        ]);
    }

    /**
     * @return array{className: string, jsonSchema: array<string, mixed>, unsupportedConstraints: list<string>}
     */
    #[McpTool(
        name: 'dlq_inspect_contract',
        description: 'Inspect the message contract for a failed message owned by the authenticated tenant.',
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'className' => ['type' => 'string'],
                'jsonSchema' => ['type' => 'object'],
                'unsupportedConstraints' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['className', 'jsonSchema', 'unsupportedConstraints'],
            'additionalProperties' => false,
        ],
    )]
    #[Schema(definition: [
        'type' => 'object',
        'properties' => [
            'messageId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
        ],
        'required' => ['messageId'],
        'additionalProperties' => false,
    ])]
    public function inspectContract(string $messageId): array
    {
        $this->authorizeViewer();
        $summary = $this->failedQueueManager->findSummaryForCurrentTenant($messageId);
        if (null === $summary) {
            throw new FailedMessageNotFoundException();
        }

        $contract = $this->contractInspector->inspect($summary->messageClass);

        return $this->boundedResponse([
            'className' => $contract->className,
            'jsonSchema' => $contract->jsonSchema,
            'unsupportedConstraints' => $contract->unsupportedConstraints,
        ]);
    }

    /**
     * @param array<string|int, mixed> $proposedPayload
     *
     * @return array{
     *     messageId: string,
     *     messageClass: string,
     *     valid: bool,
     *     violations: list<array{propertyPath: string, message: string, code: string|null}>,
     *     diff: list<array{path: string, originalValue: mixed, proposedValue: mixed}>
     * }
     */
    #[McpTool(
        name: 'dlq_dry_run_patch',
        description: 'Validate a proposed payload against the original message class without writing or replaying it.',
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'messageId' => ['type' => 'string'],
                'messageClass' => ['type' => 'string'],
                'valid' => ['type' => 'boolean'],
                'violations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'propertyPath' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                            'code' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['propertyPath', 'message', 'code'],
                        'additionalProperties' => false,
                    ],
                ],
                'diff' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string'],
                            'originalValue' => ['type' => ['string', 'number', 'integer', 'boolean', 'null', 'object', 'array']],
                            'proposedValue' => ['type' => ['string', 'number', 'integer', 'boolean', 'null', 'object', 'array']],
                        ],
                        'required' => ['path', 'originalValue', 'proposedValue'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['messageId', 'messageClass', 'valid', 'violations', 'diff'],
            'additionalProperties' => false,
        ],
    )]
    #[Schema(definition: [
        'type' => 'object',
        'properties' => [
            'messageId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
            'proposedPayload' => [
                'type' => 'object',
                'additionalProperties' => true,
                'description' => 'JSON payload; limited to 65536 bytes, 20000 values, and 64 nesting levels.',
            ],
        ],
        'required' => ['messageId', 'proposedPayload'],
        'additionalProperties' => false,
    ])]
    public function dryRunPatch(string $messageId, array $proposedPayload): array
    {
        $this->authorizeViewer();
        $result = $this->patchDryRunService->dryRun($messageId, $proposedPayload);

        return $this->boundedResponse([
            'messageId' => $result->messageId,
            'messageClass' => $result->messageClass,
            'valid' => $result->valid,
            'violations' => array_map(static fn ($violation): array => [
                'propertyPath' => $violation->propertyPath,
                'message' => $violation->message,
                'code' => $violation->code,
            ], $result->violations),
            'diff' => array_map(static fn ($change): array => [
                'path' => $change->path,
                'originalValue' => $change->originalValue,
                'proposedValue' => $change->proposedValue,
            ], $result->diff),
        ]);
    }

    /**
     * @return array{messageId: string, outcome: string}
     */
    #[McpTool(
        name: 'dlq_replay_message',
        description: 'Request replay through the configured policy; approval-required messages create a proposal instead.',
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'messageId' => ['type' => 'string'],
                'outcome' => [
                    'type' => 'string',
                    'enum' => ['replayed', 'proposal_created', 'already_processed'],
                ],
            ],
            'required' => ['messageId', 'outcome'],
            'additionalProperties' => false,
        ],
    )]
    #[Schema(definition: [
        'type' => 'object',
        'properties' => [
            'messageId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
        ],
        'required' => ['messageId'],
        'additionalProperties' => false,
    ])]
    public function replayMessage(string $messageId): array
    {
        $tenantId = $this->authorizeOperator();
        $envelope = $this->findForTenant($messageId, $tenantId);
        $outcome = $this->failedMessageProcessor->process($envelope);

        if (DlqProcessingOutcome::FORBIDDEN === $outcome) {
            throw new AccessDeniedException('The current policy forbids replaying this message.');
        }

        if (DlqProcessingOutcome::OTHER_TENANT === $outcome) {
            throw new FailedMessageNotFoundException();
        }

        if (DlqProcessingOutcome::IN_PROGRESS === $outcome) {
            throw new RuntimeException('This replay operation is already in progress.');
        }

        return $this->boundedResponse([
            'messageId' => $messageId,
            'outcome' => $outcome->value,
        ]);
    }

    /**
     * @return array{messageId: string, outcome: string}
     */
    #[McpTool(
        name: 'dlq_discard_message',
        description: 'Discard a failed message only when tenant policy permits it; approval-required actions are rejected.',
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'messageId' => ['type' => 'string'],
                'outcome' => ['type' => 'string', 'enum' => ['discarded', 'already_discarded']],
            ],
            'required' => ['messageId', 'outcome'],
            'additionalProperties' => false,
        ],
    )]
    #[Schema(definition: [
        'type' => 'object',
        'properties' => [
            'messageId' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
        ],
        'required' => ['messageId'],
        'additionalProperties' => false,
    ])]
    public function discardMessage(string $messageId): array
    {
        $this->authorizeOperator();
        $outcome = $this->failedMessageDiscarder->discard($messageId);

        return $this->boundedResponse([
            'messageId' => $messageId,
            'outcome' => $outcome->value,
        ]);
    }

    private function authorizeViewer(): ?string
    {
        if (!$this->security->isGranted('ROLE_DLQ_VIEWER')) {
            throw new AccessDeniedException('The current user cannot use DLQ Doctor tools.');
        }

        return $this->tenantContext->tenantId();
    }

    private function authorizeOperator(): ?string
    {
        $tenantId = $this->authorizeViewer();
        if (!$this->security->isGranted('ROLE_DLQ_OPERATOR')) {
            throw new AccessDeniedException('The current user cannot perform DLQ actions.');
        }

        return $tenantId;
    }

    private function findForTenant(string $messageId, ?string $tenantId): Envelope
    {
        if ('' === trim($messageId) || strlen($messageId) > 128) {
            throw new InvalidArgumentException('A message ID must contain between 1 and 128 characters.');
        }

        $envelope = $this->failedMessageReader->find($messageId);
        if (null === $envelope || !TenantScope::matches($envelope, $tenantId)) {
            throw new FailedMessageNotFoundException();
        }

        $transportMessageId = $envelope->last(TransportMessageIdStamp::class)?->getId();
        if ((string) $transportMessageId !== $messageId) {
            throw new UnexpectedValueException('The failed transport returned an envelope with a mismatched message ID.');
        }

        return $envelope;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function boundedResponse(array $response): array
    {
        $encodedResponse = json_encode($response, JSON_THROW_ON_ERROR);
        if (strlen($encodedResponse) > 262_144) {
            throw new UnexpectedValueException('An MCP tool response cannot exceed 262144 bytes.');
        }

        return $response;
    }

    /**
     * @return array{id: string, messageClass: string, errorClass: string|null, sensitivity: string}
     */
    private static function failureSummary(FailedMessageSummary $summary): array
    {
        return [
            'id' => $summary->id,
            'messageClass' => $summary->messageClass,
            'errorClass' => $summary->errorClass,
            'sensitivity' => $summary->sensitivity->value,
        ];
    }
}
