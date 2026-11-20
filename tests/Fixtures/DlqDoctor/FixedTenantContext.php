<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\DlqDoctor;

use App\DlqDoctor\Context\TenantContextInterface;

final readonly class FixedTenantContext implements TenantContextInterface
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
