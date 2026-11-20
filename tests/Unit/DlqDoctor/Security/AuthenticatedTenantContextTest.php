<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Security;

use App\DlqDoctor\Security\AuthenticatedTenantContext;
use App\DlqDoctor\Security\TenantAwareUserInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AuthenticatedTenantContextTest extends TestCase
{
    public function testAuthenticatedUserTenantTakesPrecedenceOverProcessTenant(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new TenantUser('authenticated-tenant'));

        self::assertSame(
            'authenticated-tenant',
            (new AuthenticatedTenantContext($security, 'process-tenant'))->tenantId(),
        );
    }

    public function testItRejectsUsersWithoutAnAssignedTenant(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new TenantUser('   '));

        $this->expectException(AccessDeniedException::class);
        (new AuthenticatedTenantContext($security, 'process-tenant'))->tenantId();
    }

    public function testItFailsClosedWhenNeitherAuthenticatedUserNorProcessTenantExists(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $this->expectException(AccessDeniedException::class);
        (new AuthenticatedTenantContext($security, ''))->tenantId();
    }
}

final readonly class TenantUser implements TenantAwareUserInterface
{
    public function __construct(private string $id)
    {
    }

    public function tenantId(): string
    {
        return $this->id;
    }

    public function getRoles(): array
    {
        return [];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->id;
    }
}
