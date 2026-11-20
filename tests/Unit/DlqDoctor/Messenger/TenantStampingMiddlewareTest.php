<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Messenger;

use App\DlqDoctor\Messenger\TenantContext;
use App\DlqDoctor\Messenger\TenantStamp;
use App\DlqDoctor\Messenger\TenantStampingMiddleware;
use App\DlqDoctor\Messenger\TenantScopedMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class TenantStampingMiddlewareTest extends TestCase
{
    public function testItAddsTheTrustedTenantStampToTenantScopedMessages(): void
    {
        $context = new class implements TenantContext {
            public function tenantId(): string
            {
                return 'trusted-tenant';
            }
        };
        $middleware = new TenantStampingMiddleware($context);
        $message = new TenantScopedFixtureMessage();
        $next = $this->createMock(MiddlewareInterface::class);
        $next->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static fn (Envelope $envelope): Envelope => $envelope);
        $stack = $this->createStub(StackInterface::class);
        $stack->method('next')->willReturn($next);

        $result = $middleware->handle(new Envelope($message), $stack);

        self::assertSame('trusted-tenant', $result->last(TenantStamp::class)?->tenantId);
    }

    public function testItDoesNotReplaceAnExistingTenantStamp(): void
    {
        $context = new class implements TenantContext {
            public function tenantId(): string
            {
                self::fail('An existing tenant stamp should not be replaced.');
            }
        };
        $middleware = new TenantStampingMiddleware($context);
        $next = $this->createStub(MiddlewareInterface::class);
        $next->method('handle')->willReturnCallback(static fn (Envelope $envelope): Envelope => $envelope);
        $stack = $this->createStub(StackInterface::class);
        $stack->method('next')->willReturn($next);

        $result = $middleware->handle(
            new Envelope(new TenantScopedFixtureMessage(), [new TenantStamp('existing-tenant')]),
            $stack,
        );

        self::assertSame('existing-tenant', $result->last(TenantStamp::class)?->tenantId);
    }
}

final class TenantScopedFixtureMessage implements TenantScopedMessage
{
}
