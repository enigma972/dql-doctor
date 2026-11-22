<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Service;

use App\DlqDoctor\Attribute\Sensitivity;
use App\DlqDoctor\Enum\SensitivityLevel;
use App\DlqDoctor\Service\SensitivityResolver;
use PHPUnit\Framework\TestCase;

final class SensitivityResolverTest extends TestCase
{
    public function testItResolvesTheMessageAttribute(): void
    {
        self::assertSame(
            SensitivityLevel::LOW,
            (new SensitivityResolver())->resolve(ClassifiedMessage::class),
        );
    }

    public function testItDefaultsUnclassifiedMessagesToHigh(): void
    {
        self::assertSame(
            SensitivityLevel::HIGH,
            (new SensitivityResolver())->resolve(UnclassifiedMessage::class),
        );
    }
}

#[Sensitivity(SensitivityLevel::LOW)]
final class ClassifiedMessage
{
}

final class UnclassifiedMessage
{
}
