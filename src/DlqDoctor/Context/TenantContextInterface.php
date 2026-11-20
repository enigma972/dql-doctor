<?php

declare(strict_types=1);

namespace App\DlqDoctor\Context;

interface TenantContextInterface
{
    public function tenantId(): string;

    public function actorId(): ?string;
}
