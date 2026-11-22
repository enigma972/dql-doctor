<?php

declare(strict_types=1);

namespace App\DlqDoctor\Context;

use App\DlqDoctor\Security\TenantAwareUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class TenantContext implements TenantContextInterface
{
    public function __construct(
        private TenantResolverInterface $resolver,
        private Security $security,
        #[Autowire('%dlq_doctor.require_tenant%')]
        private bool $requireTenant,
    ) {
    }

    public function resolve(): ?string
    {
        $resolvedTenant = $this->resolver->resolve();
        $user = $this->security->getUser();

        if ($user instanceof TenantAwareUserInterface) {
            $principalTenant = $this->validatedTenant($user->tenantId());
            if (null !== $resolvedTenant && $resolvedTenant !== $principalTenant) {
                throw new AccessDeniedException('The resolved tenant does not match the authenticated user.');
            }

            return $principalTenant;
        }

        if (null !== $resolvedTenant) {
            return $this->validatedTenant($resolvedTenant);
        }

        if ($this->requireTenant) {
            throw new MissingTenantContextException();
        }

        return null;
    }

    public function tenantId(): ?string
    {
        return $this->resolve();
    }

    public function actorId(): ?string
    {
        $user = $this->security->getUser();
        if (null === $user) {
            return null;
        }

        $actorId = $user->getUserIdentifier();
        if ('' === trim($actorId) || strlen($actorId) > 128) {
            throw new AccessDeniedException('The authenticated user has no valid audit identifier.');
        }

        return $actorId;
    }

    private function validatedTenant(string $tenantId): string
    {
        $tenantId = trim($tenantId);
        if ('' === $tenantId || strlen($tenantId) > 128) {
            throw new AccessDeniedException('The tenant context contains an invalid tenant identifier.');
        }

        return $tenantId;
    }
}
