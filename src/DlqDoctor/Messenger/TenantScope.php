<?php

declare(strict_types=1);

namespace App\DlqDoctor\Messenger;

use App\DlqDoctor\Context\MissingTenantContextException;
use App\DlqDoctor\Context\TenantContextInterface;
use Symfony\Component\Messenger\Envelope;

final class TenantScope
{
    public static function matches(Envelope $envelope, ?string $tenantId): bool
    {
        $tenantStamps = $envelope->all(TenantStamp::class);
        $globalStamps = $envelope->all(GlobalTenantStamp::class);

        if (null === $tenantId) {
            return [] === $tenantStamps && [] !== $globalStamps;
        }

        if ([] !== $globalStamps || [] === $tenantStamps) {
            return false;
        }

        foreach ($tenantStamps as $tenantStamp) {
            if ($tenantId !== $tenantStamp->tenantId) {
                return false;
            }
        }

        return true;
    }

    public static function requireTenantId(TenantContextInterface $context): string
    {
        return $context->tenantId() ?? throw new MissingTenantContextException();
    }
}
