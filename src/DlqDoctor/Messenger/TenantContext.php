<?php

declare(strict_types=1);

namespace App\DlqDoctor\Messenger;

interface TenantContext
{
    public function tenantId(): string;

    public function actorId(): ?string;
}
