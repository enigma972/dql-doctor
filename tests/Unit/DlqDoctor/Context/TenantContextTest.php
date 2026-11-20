<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Context;

use App\DlqDoctor\Context\MissingTenantContextException;
use App\DlqDoctor\Context\NullTenantResolver;
use App\DlqDoctor\Context\TenantContext;
use App\DlqDoctor\Context\TenantResolverInterface;
use App\Tests\Fixtures\DlqDoctor\TenantTestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class TenantContextTest extends TestCase
{
    public function testDefaultResolverProducesGlobalContextWhenTenantIsOptional(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $context = new TenantContext(new NullTenantResolver(), $security, false);

        self::assertNull($context->resolve());
    }

    public function testRequiredTenantFailsClosedWhenResolverReturnsNull(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $this->expectException(MissingTenantContextException::class);
        (new TenantContext(new NullTenantResolver(), $security, true))->resolve();
    }

    public function testAuthenticatedTenantTakesPrecedenceOverResolver(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new TenantTestUser('operator', 'principal-tenant', []));
        $resolver = new class implements TenantResolverInterface {
            public function resolve(): ?string
            {
                return null;
            }
        };

        self::assertSame('principal-tenant', (new TenantContext($resolver, $security, true))->resolve());
    }

    public function testConfiguredResolverProvidesTenantWhenNoAuthenticatedTenantExists(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);
        $resolver = new class implements TenantResolverInterface {
            public function resolve(): ?string
            {
                return 'configured-tenant';
            }
        };

        self::assertSame('configured-tenant', (new TenantContext($resolver, $security, true))->resolve());
    }

    public function testItRejectsAResolverTenantThatConflictsWithTheAuthenticatedIdentity(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new TenantTestUser('operator', 'principal-tenant', []));
        $resolver = new class implements TenantResolverInterface {
            public function resolve(): ?string
            {
                return 'different-tenant';
            }
        };

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        (new TenantContext($resolver, $security, false))->resolve();
    }

    public function testActorIsDerivedFromTheAuthenticatedIdentity(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new TenantTestUser('operator-1', 'tenant-a', []));

        self::assertSame('operator-1', (new TenantContext(new NullTenantResolver(), $security, false))->actorId());
    }

    public function testInvalidAuthenticatedTenantIsRejected(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new TenantTestUser('operator', str_repeat('t', 129), []));

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        (new TenantContext(new NullTenantResolver(), $security, false))->resolve();
    }

    public function testInvalidAuditActorIsRejected(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new TenantTestUser('', 'tenant-a', []));

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        (new TenantContext(new NullTenantResolver(), $security, false))->actorId();
    }

    public function testNullableTenantAccessorReturnsNullInGlobalMode(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        self::assertNull((new TenantContext(new NullTenantResolver(), $security, false))->tenantId());
    }
}
