<?php

declare(strict_types=1);

namespace App\Tests\Unit\DlqDoctor\Messenger;

use App\DlqDoctor\Context\TenantContextInterface;
use App\DlqDoctor\Messenger\TenantStamp;
use App\DlqDoctor\Messenger\TenantStampingMiddleware;
use App\DlqDoctor\Messenger\TenantScopedMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TenantStampingMiddlewareTest extends TestCase
{
    public function testItAddsTheTrustedTenantStampToTenantScopedMessages(): void
    {
        $context = new class implements TenantContextInterface {
            public function tenantId(): string
            {
                return 'trusted-tenant';
            }

            public function actorId(): ?string
            {
                return null;
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

    public function testItRejectsAConflictingTenantStamp(): void
    {
        $context = new class implements TenantContextInterface {
            public function tenantId(): string
            {
                return 'trusted-tenant';
            }

            public function actorId(): ?string
            {
                return null;
            }
        };
        $middleware = new TenantStampingMiddleware($context);

        $this->expectException(AccessDeniedException::class);
        $middleware->handle(new Envelope(new TenantScopedFixtureMessage(), [new TenantStamp('other-tenant')]), $this->createStub(StackInterface::class));
    }

    public function testAReceivedStampAloneCannotBypassTenantValidation(): void
    {
        $context = new class implements TenantContextInterface {
            public function tenantId(): string
            {
                return 'trusted-tenant';
            }

            public function actorId(): ?string
            {
                return null;
            }
        };

        $this->expectException(AccessDeniedException::class);
        (new TenantStampingMiddleware($context))->handle(
            new Envelope(new TenantScopedFixtureMessage(), [
                new TenantStamp('other-tenant'),
                new ReceivedStamp('async'),
            ]),
            $this->createStub(StackInterface::class),
        );
    }

    public function testItPreservesTheTenantStampOnMessagesReceivedFromATransport(): void
    {
        $context = new class implements TenantContextInterface {
            public function tenantId(): string
            {
                self::fail('A received envelope uses its transported tenant stamp.');
            }

            public function actorId(): ?string
            {
                return null;
            }
        };
        $next = $this->createStub(MiddlewareInterface::class);
        $next->method('handle')->willReturnCallback(static fn (Envelope $envelope): Envelope => $envelope);
        $stack = $this->createStub(StackInterface::class);
        $stack->method('next')->willReturn($next);

        $result = (new TenantStampingMiddleware($context))->handle(
            new Envelope(new TenantScopedFixtureMessage(), [
                new TenantStamp('received-tenant'),
                new ReceivedStamp('async'),
                new ConsumedByWorkerStamp(),
            ]),
            $stack,
        );

        self::assertSame('received-tenant', $result->last(TenantStamp::class)?->tenantId);
    }
}

final class TenantScopedFixtureMessage implements TenantScopedMessage
{
}
