<?php

declare(strict_types=1);

namespace App\DlqDoctor\Agent;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ConfiguredDlqDoctorAgent implements DlqDoctorAgentInterface
{
    public function __construct(
        private DeterministicMockAgent $deterministicAgent,
        #[Autowire(service: SymfonyAiDlqDoctorAgent::class)]
        private DlqDoctorAgentInterface $externalAgent,
        #[Autowire('%env(bool:APP_DEMO_DETERMINISTIC)%')]
        private bool $deterministic,
    ) {
    }

    public function analyze(DlqDoctorAgentRequest $request): DlqDoctorAgentResult
    {
        if ($this->deterministic) {
            return $this->deterministicAgent->analyze($request);
        }

        return $this->externalAgent->analyze($request);
    }
}
