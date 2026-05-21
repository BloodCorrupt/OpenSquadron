<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class AccountExtrasController extends AbstractController
{
    #[Route('/security', name: 'app_profile_security', methods: ['GET'])]
    public function security(): Response
    {
        return $this->render('profile/security.html.twig');
    }

    #[Route('/developer', name: 'app_profile_developer', methods: ['GET'])]
    public function developer(): Response
    {
        return $this->render('profile/developer.html.twig');
    }
}
