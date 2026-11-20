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

            if ('' !== $tenantId) {
                return $tenantId;
            }

            throw new AccessDeniedException('The authenticated user has no tenant assigned.');
        }

        if ('cli' === PHP_SAPI && '' !== trim($this->cliTenantId)) {
            return trim($this->cliTenantId);
        }

        throw new AccessDeniedException('An authenticated tenant context is required.');
    }
}
