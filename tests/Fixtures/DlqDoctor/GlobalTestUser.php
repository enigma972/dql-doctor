<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\DlqDoctor;

use Symfony\Component\Security\Core\User\UserInterface;

final readonly class GlobalTestUser implements UserInterface
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        private string $identifier,
        private array $roles,
    ) {
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }
}
