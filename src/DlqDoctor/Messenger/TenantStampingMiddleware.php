<?php

declare(strict_types=1);

namespace App\DlqDoctor\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class TenantStampingMiddleware implements MiddlewareInterface
{
    public function __construct(private TenantContext $tenantContext)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (
            !$envelope->getMessage() instanceof TenantScopedMessage
            || (null !== $envelope->last(ReceivedStamp::class) && null !== $envelope->last(ConsumedByWorkerStamp::class))
        ) {
            return $stack->next()->handle($envelope, $stack);
        }

        $tenantId = $this->tenantContext->tenantId();
        $existingTenantId = $envelope->last(TenantStamp::class)?->tenantId;

        if (null !== $existingTenantId && $existingTenantId !== $tenantId) {
            throw new AccessDeniedException('A message cannot be dispatched for a different tenant.');
        }

        if (null !== $existingTenantId) {
            return $stack->next()->handle($envelope, $stack);
        }

        return $stack->next()->handle(
            $envelope->with(new TenantStamp($tenantId)),
            $stack,
        );
    }
}
