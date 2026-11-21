<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use App\Security\DemoCredentials;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DemoUserFixture extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private readonly DemoCredentials $credentials,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public static function getGroups(): array
    {
        return ['dlq-demo'];
    }

    public function load(ObjectManager $manager): void
    {
        $password = $this->credentials->password()
            ?? throw new \LogicException('Set DLQ_DEMO_PASSWORD in the local dev environment before loading the demo fixture.');

        $existing = $manager->getRepository(User::class)->findOneBy(['username' => DemoCredentials::USERNAME]);
        if (null !== $existing) {
            if (!$existing->isDemoAccount() || !$this->hasher->isPasswordValid($existing, $password)) {
                throw new \LogicException('The existing demo username or password conflicts with the configured fixture; no account was overwritten.');
            }

            return;
        }

        $user = (new User())->setUsername(DemoCredentials::USERNAME)->setRoles(['ROLE_DLQ_OPERATOR']);
        $user->markAsDemoAccount();
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $manager->persist($user);
        $manager->flush();
    }
}
