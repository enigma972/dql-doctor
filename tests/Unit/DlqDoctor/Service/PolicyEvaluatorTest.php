<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Service;

use App\DlqDoctor\Enum\DlqAction;
use App\DlqDoctor\Enum\PolicyDecision;
use App\DlqDoctor\Enum\SensitivityLevel;
use App\DlqDoctor\Service\PolicyEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyEvaluatorTest extends TestCase
{
    #[DataProvider('policyCases')]
    public function testItEvaluatesEveryConfiguredPolicy(
        SensitivityLevel $sensitivity,
        DlqAction $action,
        PolicyDecision $expected,
    ): void {
        self::assertSame($expected, (new PolicyEvaluator(self::policy()))->evaluate($sensitivity, $action));
    }

    public function testItRejectsAnIncompletePolicyMatrix(): void
    {
        $policy = self::policy();
        unset($policy[SensitivityLevel::HIGH->value][DlqAction::DISCARD->value]);

        $this->expectException(\InvalidArgumentException::class);
        new PolicyEvaluator($policy);
    }

    public static function policyCases(): iterable
    {
        yield 'low replay' => [SensitivityLevel::LOW, DlqAction::REPLAY, PolicyDecision::AUTO_AUDIT];
        yield 'low patch' => [SensitivityLevel::LOW, DlqAction::PATCH, PolicyDecision::AUTO_AUDIT];
        yield 'low discard' => [SensitivityLevel::LOW, DlqAction::DISCARD, PolicyDecision::APPROVAL];
        yield 'medium replay' => [SensitivityLevel::MEDIUM, DlqAction::REPLAY, PolicyDecision::APPROVAL];
        yield 'medium patch' => [SensitivityLevel::MEDIUM, DlqAction::PATCH, PolicyDecision::APPROVAL];
        yield 'medium discard' => [SensitivityLevel::MEDIUM, DlqAction::DISCARD, PolicyDecision::APPROVAL];
        yield 'high replay' => [SensitivityLevel::HIGH, DlqAction::REPLAY, PolicyDecision::APPROVAL];
        yield 'high patch' => [SensitivityLevel::HIGH, DlqAction::PATCH, PolicyDecision::APPROVAL];
        yield 'high discard' => [SensitivityLevel::HIGH, DlqAction::DISCARD, PolicyDecision::FORBIDDEN];
    }

    private static function policy(): array
    {
        return [
            SensitivityLevel::LOW->value => [
                DlqAction::REPLAY->value => PolicyDecision::AUTO_AUDIT->value,
                DlqAction::PATCH->value => PolicyDecision::AUTO_AUDIT->value,
                DlqAction::DISCARD->value => PolicyDecision::APPROVAL->value,
            ],
            SensitivityLevel::MEDIUM->value => [
                DlqAction::REPLAY->value => PolicyDecision::APPROVAL->value,
                DlqAction::PATCH->value => PolicyDecision::APPROVAL->value,
                DlqAction::DISCARD->value => PolicyDecision::APPROVAL->value,
            ],
            SensitivityLevel::HIGH->value => [
                DlqAction::REPLAY->value => PolicyDecision::APPROVAL->value,
                DlqAction::PATCH->value => PolicyDecision::APPROVAL->value,
                DlqAction::DISCARD->value => PolicyDecision::FORBIDDEN->value,
            ],
        ];
    }
}