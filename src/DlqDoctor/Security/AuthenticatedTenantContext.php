<?php

declare(strict_types=1);

namespace App\DlqDoctor\Security;

use App\DlqDoctor\Messenger\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class AuthenticatedTenantContext implements TenantContext
{
    public function __construct(
        private Security $security,
        #[Autowire('%env(DLQ_TENANT_ID)%')]
        private string $cliTenantId,
    ) {
    }

    public function tenantId(): string
    {
        $user = $this->security->getUser();

        if ($user instanceof TenantAwareUserInterface) {
            $tenantId = trim($user->tenantId());

            if ('' !== $tenantId && strlen($tenantId) <= 128) {
                return $tenantId;
            }

            throw new AccessDeniedException('The authenticated user has no valid tenant assigned.');
        }

        $cliTenantId = trim($this->cliTenantId);
        if ('cli' === PHP_SAPI && '' !== $cliTenantId && strlen($cliTenantId) <= 128) {
            return $cliTenantId;
        }

        throw new AccessDeniedException('An authenticated tenant context is required.');
    }

    public function actorId(): ?string
    {
        $user = $this->security->getUser();

        if (!$user instanceof TenantAwareUserInterface) {
            return null;
        }

        $actorId = $user->getUserIdentifier();
        if ('' === trim($actorId) || strlen($actorId) > 128) {
            throw new AccessDeniedException('The authenticated user has no valid audit identifier.');
        }

        return $actorId;
    }
}
