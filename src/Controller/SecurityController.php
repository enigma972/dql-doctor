<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\DemoCredentials;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->redirectToRoute('dlq_proposals_index');
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, DemoCredentials $demoCredentials): Response
    {
        $demoPassword = $demoCredentials->password();

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'demo_username' => null !== $demoPassword ? DemoCredentials::USERNAME : null,
            'demo_password' => $demoPassword,
        ], new Response(headers: ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): void
    {
        throw new \LogicException('Symfony Security must intercept the logout route.');
    }
}
