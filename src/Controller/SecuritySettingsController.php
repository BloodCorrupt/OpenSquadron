<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class SecuritySettingsController extends AbstractController
{
    #[Route('/settings/security/backup-codes', name: 'app_generate_backup_codes', methods: ['GET', 'POST'])]
    public function generateBackupCodes(EntityManagerInterface $entityManager, \Symfony\Component\HttpFoundation\Request $request): Response
    {
        /** @var \App\Entity\Admin $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        if (!$user->isTotpAuthenticationEnabled()) {
            $this->addFlash('error', 'You must enable Two-Factor Authentication before generating backup codes.');
            return $this->redirectToRoute('app_accounts_edit_self');
        }

        $codes = [];
        $generated = false;

        if ($request->isMethod('POST')) {
            // Generate 8 new backup codes, each 8 characters long
            for ($i = 0; $i < 8; $i++) {
                $codes[] = bin2hex(random_bytes(4));
            }

            $user->setBackupCodes($codes);
            $entityManager->flush();
            $generated = true;
        }

        return $this->render('profile/backup_codes.html.twig', [
            'backup_codes' => $codes,
            'generated' => $generated,
            'has_existing' => !empty($user->getBackupCodes())
        ]);
    }
}
