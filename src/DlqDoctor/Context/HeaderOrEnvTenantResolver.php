<?php

declare(strict_types=1);

namespace App\DlqDoctor\Context;

use App\DlqDoctor\Security\TenantAwareUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class HeaderOrEnvTenantResolver implements TenantResolverInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private Security $security,
        #[Autowire('%env(MCP_TENANT_ID)%')]
        private string $stdioTenantId,
    ) {
    }

    public function resolve(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null !== $request) {
            $headerTenantId = $request->headers->get('X-Tenant-ID');
            if (null === $headerTenantId || '' === trim($headerTenantId) || !$request->isFromTrustedProxy()) {
                return null;
            }

            $user = $this->security->getUser();
            if (!$user instanceof TenantAwareUserInterface) {
                return null;
            }

            $principalTenantId = trim($user->tenantId());
            if ('' === $principalTenantId || strlen($principalTenantId) > 128
                || strlen($headerTenantId) > 128
                || !hash_equals($principalTenantId, trim($headerTenantId))
            ) {
                throw new AccessDeniedException('The X-Tenant-ID header does not match the authenticated tenant.');
            }

            return $principalTenantId;
        }

        if ('cli' !== PHP_SAPI || '' === trim($this->stdioTenantId)) {
            return null;
        }

        $tenantId = trim($this->stdioTenantId);
        if (strlen($tenantId) > 128) {
            throw new AccessDeniedException('MCP_TENANT_ID exceeds the supported tenant identifier length.');
        }

        return $tenantId;
    }
}
