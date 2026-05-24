<?php

namespace App\Controller;

use App\Entity\Admin;
use App\Entity\SmtpSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class SmtpSettingsController extends AbstractController
{
    #[Route('/settings/smtp', name: 'app_smtp_settings', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var Admin $user */
        $user = $this->getUser();

        // Only super_admin and admin can configure SMTP
        if (!in_array($user->getAccountType(), ['super_admin', 'admin'])) {
            throw $this->createAccessDeniedException('Only workspace owners can configure SMTP settings.');
        }

        $smtpSettings = $user->getSmtpSettings();
        if (!$smtpSettings) {
            $smtpSettings = new SmtpSettings();
            $smtpSettings->setOwner($user);
        }

        if ($request->isMethod('POST')) {
            $smtpSettings->setHost($request->request->get('host'));
            $smtpSettings->setPort((int)$request->request->get('port'));
            $smtpSettings->setUsername($request->request->get('username'));
            
            $password = $request->request->get('password');
            if (!empty($password)) {
                // Remove any spaces often included in App Passwords
                $password = str_replace(' ', '', $password);
                $smtpSettings->setPassword($password); // We store it as plaintext for simplicity in this MVP
            }

            $encryption = $request->request->get('encryption');
            if (empty($encryption)) {
                $encryption = null;
            }
            $smtpSettings->setEncryption($encryption);
            $smtpSettings->setFromEmail($request->request->get('fromEmail'));
            $smtpSettings->setFromName($request->request->get('fromName'));

            $registrationEnabled = $request->request->getBoolean('registrationEnabled', false);
            
            if ($registrationEnabled && $user->getAccountType() !== 'super_admin') {
                $branding = $user->getBranding();
                if (!$branding || empty($branding->getCustomDomain())) {
                    $this->addFlash('error', 'You must configure a Custom Domain in your Branding Settings before you can enable Public Registration.');
                    $registrationEnabled = false;
                }
            }
            
            $user->setRegistrationEnabled($registrationEnabled);

            $entityManager->persist($smtpSettings);
            $entityManager->flush();

            if ($registrationEnabled === false && $request->request->getBoolean('registrationEnabled', false)) {
                // If it was forced off due to error, don't show success flash for that part
            } else {
                $this->addFlash('success', 'SMTP settings and preferences have been saved successfully.');
            }

            return $this->redirectToRoute('app_smtp_settings');
        }

        return $this->render('smtp_settings/index.html.twig', [
            'smtpSettings' => $smtpSettings,
            'user' => $user,
        ]);
    }

    #[Route('/settings/smtp/test', name: 'app_smtp_settings_test', methods: ['POST'])]
    public function testEmail(Request $request, EntityManagerInterface $entityManager): Response
    {
        /** @var Admin $user */
        $user = $this->getUser();

        if (!in_array($user->getAccountType(), ['super_admin', 'admin'])) {
            throw $this->createAccessDeniedException('Only workspace owners can configure SMTP settings.');
        }

        $smtpSettings = $user->getSmtpSettings();
        if (!$smtpSettings || !$smtpSettings->getHost() || !$smtpSettings->getPort()) {
            $this->addFlash('error', 'Please configure and save your SMTP settings first.');
            return $this->redirectToRoute('app_smtp_settings');
        }

        $testEmailAddress = $request->request->get('testEmail');
        if (empty($testEmailAddress)) {
            $this->addFlash('error', 'Recipient email is required.');
            return $this->redirectToRoute('app_smtp_settings');
        }

        try {
            $encryption = $smtpSettings->getEncryption();
            $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';
            
            $dsn = sprintf(
                '%s://%s:%s@%s:%d',
                $scheme,
                urlencode($smtpSettings->getUsername() ?? ''),
                urlencode($smtpSettings->getPassword() ?? ''),
                $smtpSettings->getHost(),
                $smtpSettings->getPort()
            );

            $transport = \Symfony\Component\Mailer\Transport::fromDsn($dsn);
            $mailer = new \Symfony\Component\Mailer\Mailer($transport);

            $email = (new \Symfony\Component\Mime\Email())
                ->from(new \Symfony\Component\Mime\Address($smtpSettings->getFromEmail() ?? 'test@opensquadron.com', $smtpSettings->getFromName() ?? 'OpenSquadron'))
                ->to($testEmailAddress)
                ->subject('Test Email from OpenSquadron')
                ->html('<p>This is a test email to verify your SMTP settings are working correctly.</p><p>If you received this, your configuration is successful!</p>');

            $mailer->send($email);

            $this->addFlash('success', 'Test email sent successfully to ' . $testEmailAddress);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to send test email: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_smtp_settings');
    }
}
