<?php

declare(strict_types=1);

namespace App\DlqDoctor\Context;

interface TenantResolverInterface
{
    public function resolve(): ?string;
}
