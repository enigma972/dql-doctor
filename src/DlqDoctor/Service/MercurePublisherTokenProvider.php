<?php

declare(strict_types=1);

namespace App\DlqDoctor\Service;

use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;

final readonly class MercurePublisherTokenProvider implements TokenProviderInterface
{
    public function __construct(
        #[Autowire(service: 'Symfony\Component\Mercure\Jwt\TokenFactoryInterface $defaultFactory')]
        private TokenFactoryInterface $tokenFactory,
        private ProposalTopic $proposalTopic,
        #[Autowire('%dlq_doctor.require_tenant%')]
        private bool $requireTenant,
        #[Autowire('%env(default::MERCURE_URL)%')]
        private string $hubUrl,
        #[Autowire('%env(default::MERCURE_PUBLIC_URL)%')]
        private string $publicUrl,
    ) {
    }

    public function getJwt(): string
    {
        $audiences = array_values(array_unique(array_filter(
            [$this->hubUrl, $this->publicUrl],
            static fn (string $url): bool => '' !== $url,
        )));
        if ([] === $audiences) {
            throw new LogicException('Mercure publisher tokens require a configured hub URL audience.');
        }

        $grants = [
            new Grant(
                [Grant::ACTION_PUBLISH],
                ['urlpattern' => [$this->proposalTopic->tenantPattern()]],
            ),
        ];
        if (!$this->requireTenant) {
            $grants[] = new Grant(
                [Grant::ACTION_PUBLISH],
                ['exact' => [$this->proposalTopic->forTenant(null)]],
            );
        }

        return $this->tokenFactory->create($grants, ['aud' => $audiences]);
    }
}
