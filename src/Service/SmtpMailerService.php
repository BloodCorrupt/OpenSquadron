<?php

namespace App\Service;

use App\Entity\Admin;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class SmtpMailerService
{
    public function __construct(
        private Environment $twig
    ) {}

    public function sendWelcomeEmail(Admin $creator, Admin $newUser, string $plainPassword): void
    {
        $smtpSettings = $creator->getSmtpSettings();

        if (!$smtpSettings) {
            throw new \Exception('Creator does not have SMTP settings configured.');
        }

        // Build DSN
        // format: smtp://user:pass@host:port
        $dsn = 'smtp://';
        if ($smtpSettings->getUsername()) {
            $dsn .= urlencode($smtpSettings->getUsername());
            if ($smtpSettings->getPassword()) {
                $dsn .= ':' . urlencode($smtpSettings->getPassword());
            }
            $dsn .= '@';
        }
        
        $dsn .= $smtpSettings->getHost() . ':' . $smtpSettings->getPort();

        // Optional parameters (like encryption) could be appended, e.g. ?encryption=tls
        // Actually, Symfony Mailer determines encryption mostly by port, but we can enforce it:
        // if ($smtpSettings->getEncryption()) {
        //     $dsn .= '?verify_peer=0'; // If we wanted to bypass ssl checks etc.
        // }

        $transport = Transport::fromDsn($dsn);
        $mailer = new Mailer($transport);

        $brandName = 'OpenSquadron';
        if ($creator->getBranding() && $creator->getBranding()->getBrandName()) {
            $brandName = $creator->getBranding()->getBrandName();
        }

        $html = $this->twig->render('emails/welcome.html.twig', [
            'user' => $newUser,
            'plainPassword' => $plainPassword,
            'creator' => $creator,
            'brandName' => $brandName,
        ]);

        $email = (new Email())
            ->from(new \Symfony\Component\Mime\Address($smtpSettings->getFromEmail(), $smtpSettings->getFromName()))
            ->to($newUser->getEmail())
            ->subject('Welcome to ' . $brandName . ' - Action Required')
            ->html($html);

        $mailer->send($email);
    }

    public function sendPasswordResetEmail(Admin $creator, Admin $targetUser, string $resetToken): void
    {
        $smtpSettings = $creator->getSmtpSettings();

        if (!$smtpSettings) {
            throw new \Exception('Creator does not have SMTP settings configured.');
        }

        $dsn = 'smtp://';
        if ($smtpSettings->getUsername()) {
            $dsn .= urlencode($smtpSettings->getUsername());
            if ($smtpSettings->getPassword()) {
                $dsn .= ':' . urlencode($smtpSettings->getPassword());
            }
            $dsn .= '@';
        }
        
        $dsn .= $smtpSettings->getHost() . ':' . $smtpSettings->getPort();

        $transport = Transport::fromDsn($dsn);
        $mailer = new Mailer($transport);

        // Assuming a standard domain or passing absolute URL from controller. 
        // For simplicity, we can use Twig's url() helper in the template if we pass the token.
        // The template should use: {{ url('app_reset_password', {'token': resetToken}) }}
        $html = $this->twig->render('emails/reset_password.html.twig', [
            'user' => $targetUser,
            'creator' => $creator,
            'resetUrl' => 'http://' . $_SERVER['HTTP_HOST'] . '/reset-password?token=' . $resetToken,
        ]);

        $email = (new Email())
            ->from(new \Symfony\Component\Mime\Address($smtpSettings->getFromEmail(), $smtpSettings->getFromName()))
            ->to($targetUser->getEmail())
            ->subject('Password Reset Request - ' . $creator->getName())
            ->html($html);

        $mailer->send($email);
    }
}
