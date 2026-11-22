<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class DemoUserChecker implements UserCheckerInterface
{
    public function __construct(#[Autowire('%kernel.environment%')] private string $environment)
    {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if ('dev' !== $this->environment && $user instanceof User && $user->isDemoAccount()) {
            throw new DisabledException('Demo accounts are disabled outside development.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
