<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\DlqDoctor;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class TenantTestUserProvider implements UserProviderInterface
{
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof TenantTestUser) {
            throw new UnsupportedUserException(sprintf('Unsupported test user "%s".', $user::class));
        }

        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, TenantTestUser::class, true);
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        throw new UserNotFoundException(sprintf('No configured test user "%s".', $identifier));
    }
}
