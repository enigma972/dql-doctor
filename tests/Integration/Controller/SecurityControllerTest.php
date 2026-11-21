<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SecurityControllerTest extends WebTestCase
{
    public function testLoginAndLogoutProtectProposals(): void
    {
        $client = static::createClient();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $identifier = 'operator-'.bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(16));
        $user = (new User())->setUsername($identifier)->setRoles(['ROLE_DLQ_OPERATOR']);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $entityManager->persist($user);
        $entityManager->flush();

        try {
            $client->request('GET', '/');
            self::assertResponseRedirects('/proposals');
            $client->followRedirect();
            self::assertResponseRedirects('/login');
            $client->followRedirect();
            self::assertSelectorExists('input[name="_password"][autocomplete="current-password"]');
            self::assertSelectorNotExists('input[name="_password"][placeholder]');

            $client->submitForm('Sign in', ['_username' => $identifier, '_password' => $password, '_csrf_token' => 'invalid']);
            self::assertResponseRedirects('/login');
            $client->followRedirect();
            self::assertSelectorExists('[role="alert"]');

            $client->submitForm('Sign in', ['_username' => $identifier, '_password' => 'incorrect']);
            self::assertResponseRedirects('/login');
            $client->followRedirect();
            self::assertSelectorExists('[role="alert"]');

            $client->submitForm('Sign in', ['_username' => $identifier, '_password' => $password]);
            self::assertResponseRedirects('/proposals');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $client->submitForm('Sign out');
            self::assertResponseRedirects('/login');
            $client->request('GET', '/proposals');
            self::assertResponseRedirects('/login');

            $entityManager = static::getContainer()->get(EntityManagerInterface::class);
            $entityManager->find(User::class, $user->getId())->markAsDemoAccount();
            $entityManager->flush();

            $client->followRedirect();
            $client->submitForm('Sign in', ['_username' => $identifier, '_password' => $password]);
            self::assertResponseRedirects('/login', message: 'A dev-only account cannot sign in outside dev.');
        } finally {
            $entityManager = static::getContainer()->get(EntityManagerInterface::class);
            $entityManager->remove($entityManager->find(User::class, $user->getId()));
            $entityManager->flush();
        }
    }

    public function testHomeRedirectsToProposals(): void
    {
        static::createClient()->request('GET', '/');
        self::assertResponseRedirects('/proposals');
    }
}
