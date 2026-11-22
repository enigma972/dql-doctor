<?php

declare(strict_types=1);

namespace App\DlqDoctor\Agent;

use InvalidArgumentException;
use JsonException;
use LogicException;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use UnexpectedValueException;

final readonly class SymfonyAiDlqDoctorAgent implements DlqDoctorAgentInterface
{
    public function __construct(
        #[Autowire(service: 'ai.agent.dlq_doctor')]
        private AgentInterface $agent,
    ) {
    }

    public function analyze(DlqDoctorAgentRequest $request): DlqDoctorAgentResult
    {
        try {
            $input = json_encode($request->toArray(), JSON_THROW_ON_ERROR, 64);
        } catch (JsonException $exception) {
            throw new LogicException('The DLQ agent request could not be encoded as JSON.', previous: $exception);
        }

        $execution = $this->agent->call($input, [
            'response_format' => SymfonyAiAgentResponse::class,
        ]);
        $response = $execution->asObject(SymfonyAiAgentResponse::class);

        if (!$response instanceof SymfonyAiAgentResponse) {
            throw new LogicException('The AI provider returned an invalid structured DLQ analysis.');
        }

        $proposedPayload = null;
        if (null !== $response->proposedPayloadJson) {
            try {
                $decodedObject = json_decode($response->proposedPayloadJson, false, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new UnexpectedValueException('The AI provider returned an invalid JSON patch.', previous: $exception);
            }

            if (!$decodedObject instanceof \stdClass) {
                throw new UnexpectedValueException('The AI provider patch must be a JSON object.');
            }

            $proposedPayload = json_decode($response->proposedPayloadJson, true, 64, JSON_THROW_ON_ERROR);
        }

        try {
            return new DlqDoctorAgentResult($response->diagnosis, $proposedPayload);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException('The AI provider returned an invalid DLQ diagnosis or patch.', previous: $exception);
        }
    }
}
