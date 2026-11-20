<?php

declare(strict_types=1);

namespace App\DlqDoctor\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final readonly class TenantStampingMiddleware implements MiddlewareInterface
{
    public function __construct(private TenantContext $tenantContext)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$envelope->getMessage() instanceof TenantScopedMessage || null !== $envelope->last(TenantStamp::class)) {
            return $stack->next()->handle($envelope, $stack);
        }

        return $stack->next()->handle(
            $envelope->with(new TenantStamp($this->tenantContext->tenantId())),
            $stack,
        );
    }
}
