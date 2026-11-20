<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use App\DlqDoctor\Enum\DlqAction;
use App\DlqDoctor\Enum\PolicyDecision;
use App\DlqDoctor\Enum\SensitivityLevel;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PolicyEvaluator
{
    /**
     * @param array<string, array<string, string>> $policy
     */
    public function __construct(
        #[Autowire('%dlq_doctor.policy%')]
        private array $policy,
    ) {
        foreach (SensitivityLevel::cases() as $sensitivity) {
            foreach (DlqAction::cases() as $action) {
                $decision = $policy[$sensitivity->value][$action->value] ?? null;

                if (!is_string($decision) || PolicyDecision::tryFrom($decision) === null) {
                    throw new InvalidArgumentException(sprintf(
                        'Missing or invalid DLQ policy decision for sensitivity "%s" and action "%s".',
                        $sensitivity->value,
                        $action->value,
                    ));
                }
            }
        }
    }

    public function evaluate(SensitivityLevel $sensitivity, DlqAction $action): PolicyDecision
    {
        return PolicyDecision::from($this->policy[$sensitivity->value][$action->value]);
    }
}