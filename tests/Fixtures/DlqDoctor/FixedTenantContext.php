<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\DlqDoctor;

use App\DlqDoctor\Messenger\TenantContext;

final readonly class FixedTenantContext implements TenantContext
{
    public function __construct(
        private string $tenant,
        private ?string $actor = null,
    ) {
    }

    public function tenantId(): string
    {
        return $this->tenant;
    }

    public function actorId(): ?string
    {
        return $this->actor;
    }
}
