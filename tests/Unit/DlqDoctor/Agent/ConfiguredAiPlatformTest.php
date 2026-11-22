<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Agent;

use App\DlqDoctor\Agent\ConfiguredAiPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ConfiguredAiPlatformTest extends TestCase
{
    #[DataProvider('providers')]
    public function testSelectsOnlyTheConfiguredProvider(string $name): void
    {
        $catalog = $this->createStub(ModelCatalogInterface::class);
        $selected = $this->createMock(PlatformInterface::class);
        $selected->expects(self::once())->method('getModelCatalog')->willReturn($catalog);
        $other = static function (): never {
            throw new \LogicException('Unselected provider must not be instantiated.');
        };
        $services = ['openai' => $other, 'anthropic' => $other, 'gemini' => $other, 'huggingface' => $other];
        $services[$name] = static fn () => $selected;

        $platform = new ConfiguredAiPlatform(new ServiceLocator($services), $name, 'key', 'key', 'key', 'key');

        self::assertSame($catalog, $platform->getModelCatalog());
    }

    public static function providers(): iterable
    {
        yield ['openai'];
        yield ['anthropic'];
        yield ['gemini'];
        yield ['huggingface'];
    }

    #[DataProvider('providers')]
    public function testMissingSelectedKeyFailsBeforeProviderIsInstantiated(string $name): void
    {
        $platform = new ConfiguredAiPlatform(new ServiceLocator([]), $name, '', '', '', '');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(('huggingface' === $name ? 'HUGGINGFACE' : strtoupper($name)).'_API_KEY');
        $platform->getModelCatalog();
    }

    public function testUnknownProviderFailsExplicitly(): void
    {
        $platform = new ConfiguredAiPlatform(new ServiceLocator([]), 'unknown', '', '', '', '');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('DLQ_AI_PROVIDER');
        $platform->getModelCatalog();
    }
}
