<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\DlqDoctor;

use App\DlqDoctor\Security\TenantAwareUserInterface;

final readonly class TenantTestUser implements TenantAwareUserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private string $identifier,
        private string $tenant,
        private array $roles,
    ) {
    }

    public function tenantId(): string
    {
        return $this->tenant;
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function eraseCredentials(): void
    {
    }
}
