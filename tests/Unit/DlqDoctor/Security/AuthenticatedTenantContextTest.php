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

    public function testContextResolvesTheCurrentIdentityRatherThanCachingThePreviousTenant(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects(self::exactly(2))
            ->method('getUser')
            ->willReturnOnConsecutiveCalls(new TenantUser('tenant-a'), new TenantUser('tenant-b'));
        $context = new AuthenticatedTenantContext($security, 'process-tenant');

        self::assertSame('tenant-a', $context->tenantId());
        self::assertSame('tenant-b', $context->tenantId());
    }

    public function testItRejectsUsersWithoutAnAssignedTenant(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new TenantUser('   '));

        $this->expectException(AccessDeniedException::class);
        (new AuthenticatedTenantContext($security, 'process-tenant'))->tenantId();
    }

    public function testItRejectsTenantIdsThatExceedTheStorageLimit(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new TenantUser(str_repeat('t', 129)));

        $this->expectException(AccessDeniedException::class);
        (new AuthenticatedTenantContext($security, 'process-tenant'))->tenantId();
    }

    public function testItResolvesTheAuditActorFromTheAuthenticatedUser(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new TenantUser('authenticated-actor'));

        self::assertSame('authenticated-actor', (new AuthenticatedTenantContext($security, 'process-tenant'))->actorId());
    }

    public function testCliContextHasNoAuthenticatedActor(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        self::assertNull((new AuthenticatedTenantContext($security, 'process-tenant'))->actorId());
    }

    public function testItFailsClosedWhenNeitherAuthenticatedUserNorProcessTenantExists(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $this->expectException(AccessDeniedException::class);
        (new AuthenticatedTenantContext($security, ''))->tenantId();
    }

    public function testItRejectsAnOversizedProcessTenant(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $this->expectException(AccessDeniedException::class);
        (new AuthenticatedTenantContext($security, str_repeat('t', 129)))->tenantId();
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
