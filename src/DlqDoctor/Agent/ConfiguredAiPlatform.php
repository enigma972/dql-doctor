<?php

declare(strict_types=1);

namespace App\DlqDoctor\Agent;

use LogicException;
use Psr\Container\ContainerInterface;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class ConfiguredAiPlatform implements PlatformInterface
{
    public function __construct(
        #[AutowireLocator([
            'openai' => new Autowire(service: 'ai.platform.openai'),
            'anthropic' => new Autowire(service: 'ai.platform.anthropic'),
            'gemini' => new Autowire(service: 'ai.platform.gemini'),
        ])]
        private ContainerInterface $platforms,
        #[Autowire('%env(DLQ_AI_PROVIDER)%')]
        private string $provider,
        #[\SensitiveParameter]
        #[Autowire('%env(OPENAI_API_KEY)%')]
        private string $openAiKey,
        #[\SensitiveParameter]
        #[Autowire('%env(ANTHROPIC_API_KEY)%')]
        private string $anthropicKey,
        #[\SensitiveParameter]
        #[Autowire('%env(GEMINI_API_KEY)%')]
        private string $geminiKey,
    ) {
    }

    public function invoke(string|Model $model, array|string|object $input, array $options = []): DeferredResult
    {
        try {
            return $this->platform()->invoke($model, $input, $options);
        } catch (ModelNotFoundException $exception) {
            throw new ModelNotFoundException(sprintf(
                'The selected model "%s" is unavailable in the installed catalog for provider "%s". Check DLQ_AI_MODEL and DLQ_AI_PROVIDER, or update the provider bridge.',
                $model instanceof Model ? $model->getName() : $model,
                $this->provider,
            ), previous: $exception);
        }
    }

    public function getModelCatalog(): ModelCatalogInterface
    {
        return $this->platform()->getModelCatalog();
    }

    private function platform(): PlatformInterface
    {
        [$key, $variable] = match ($this->provider) {
            'openai' => [$this->openAiKey, 'OPENAI_API_KEY'],
            'anthropic' => [$this->anthropicKey, 'ANTHROPIC_API_KEY'],
            'gemini' => [$this->geminiKey, 'GEMINI_API_KEY'],
            default => throw new LogicException('DLQ_AI_PROVIDER must be one of: openai, anthropic, gemini.'),
        };

        if ('' === trim($key)) {
            throw new LogicException(sprintf('%s must be configured for AI provider "%s".', $variable, $this->provider));
        }

        $platform = $this->platforms->get($this->provider);
        if (!$platform instanceof PlatformInterface) {
            throw new LogicException(sprintf('The configured AI provider "%s" is not a platform.', $this->provider));
        }

        return $platform;
    }
}
