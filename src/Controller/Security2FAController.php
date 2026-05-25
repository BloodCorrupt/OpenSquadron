<?php

namespace App\Controller;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class Security2FAController extends AbstractController
{
    #[Route(path: '/2fa', name: '2fa_login')]
    public function check2fa(): Response
    {
        return $this->render('security/2fa_form.html.twig');
    }

    #[Route(path: '/2fa_check', name: '2fa_login_check')]
    public function check2faCheck(): void
    {
        throw new LogicException('This method can be blank - it will be intercepted by the firewall.');
    }
}
