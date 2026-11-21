<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class DemoCredentials
{
    public const string USERNAME = 'demo';

    public function __construct(
        #[Autowire('%kernel.environment%')] private string $environment,
        #[Autowire('%env(default::DLQ_DEMO_PASSWORD)%')] private ?string $password,
    ) {
    }

    public function password(): ?string
    {
        return 'dev' === $this->environment && null !== $this->password && '' !== trim($this->password)
            ? $this->password
            : null;
    }
}
