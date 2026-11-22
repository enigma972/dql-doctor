<?php

declare(strict_types=1);

namespace App\DlqDoctor\Agent;

use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfiguredDlqDoctorAgent implements DlqDoctorAgentInterface
{
    public function __construct(
        private DeterministicMockAgent $deterministicAgent,
        #[Autowire(service: SymfonyAiDlqDoctorAgent::class)]
        private DlqDoctorAgentInterface $externalAgent,
        #[Autowire('%env(bool:APP_DEMO_DETERMINISTIC)%')]
        private bool $deterministic,
        #[Autowire('%env(OPENAI_API_KEY)%')]
        private string $apiKey,
    ) {
    }

    public function analyze(DlqDoctorAgentRequest $request): DlqDoctorAgentResult
    {
        if ($this->deterministic) {
            return $this->deterministicAgent->analyze($request);
        }

        if ('' === trim($this->apiKey)) {
            throw new LogicException('OPENAI_API_KEY must be configured when APP_DEMO_DETERMINISTIC is false.');
        }

        return $this->externalAgent->analyze($request);
    }
}
