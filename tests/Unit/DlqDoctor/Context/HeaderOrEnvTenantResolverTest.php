<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Context;

use App\DlqDoctor\Context\HeaderOrEnvTenantResolver;
use App\Tests\Fixtures\DlqDoctor\TenantTestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class HeaderOrEnvTenantResolverTest extends TestCase
{
    protected function setUp(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], -1);
    }

    public function testHttpHeaderIsAcceptedOnlyWhenItMatchesTheAuthenticatedTenant(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', server: ['HTTP_X_TENANT_ID' => 'tenant-a', 'REMOTE_ADDR' => '127.0.0.1']));
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new TenantTestUser('operator', 'tenant-a', []));

        self::assertSame('tenant-a', (new HeaderOrEnvTenantResolver($requestStack, $security, ''))->resolve());
    }

    public function testHttpHeaderCannotSelectAnotherTenant(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', server: ['HTTP_X_TENANT_ID' => 'tenant-b', 'REMOTE_ADDR' => '127.0.0.1']));
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new TenantTestUser('operator', 'tenant-a', []));

        $this->expectException(AccessDeniedException::class);
        (new HeaderOrEnvTenantResolver($requestStack, $security, ''))->resolve();
    }

    public function testHttpHeaderDoesNotGrantTenantToAnUnscopedUser(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', server: ['HTTP_X_TENANT_ID' => 'tenant-a', 'REMOTE_ADDR' => '127.0.0.1']));
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        self::assertNull((new HeaderOrEnvTenantResolver($requestStack, $security, ''))->resolve());
    }

    public function testProcessTenantIsOnlyUsedOutsideHttpRequests(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        self::assertSame(
            'stdio-tenant',
            (new HeaderOrEnvTenantResolver(new RequestStack(), $security, 'stdio-tenant'))->resolve(),
        );
    }

    public function testBlankProcessTenantResolvesToNull(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        self::assertNull((new HeaderOrEnvTenantResolver(new RequestStack(), $security, '  '))->resolve());
    }

    public function testOversizedProcessTenantIsRejected(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $this->expectException(AccessDeniedException::class);
        (new HeaderOrEnvTenantResolver(new RequestStack(), $security, str_repeat('t', 129)))->resolve();
    }

    public function testInvalidHttpTenantValueIsRejected(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', server: ['HTTP_X_TENANT_ID' => str_repeat('t', 129), 'REMOTE_ADDR' => '127.0.0.1']));
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new TenantTestUser('operator', str_repeat('t', 129), []));

        $this->expectException(AccessDeniedException::class);
        (new HeaderOrEnvTenantResolver($requestStack, $security, ''))->resolve();
    }

    public function testUntrustedProxyHeaderIsIgnored(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', server: ['HTTP_X_TENANT_ID' => 'tenant-a', 'REMOTE_ADDR' => '192.0.2.10']));
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new TenantTestUser('operator', 'tenant-a', []));

        self::assertNull((new HeaderOrEnvTenantResolver($requestStack, $security, ''))->resolve());
    }
}
