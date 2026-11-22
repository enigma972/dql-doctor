<?php

declare(strict_types=1);

namespace App\DlqDoctor\Context;

final class NullTenantResolver implements TenantResolverInterface
{
    public function resolve(): ?string
    {
        return null;
    }
}
