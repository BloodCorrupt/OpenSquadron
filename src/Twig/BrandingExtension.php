<?php

namespace App\Twig;

use App\Entity\Admin;
use App\Entity\ResellerBranding;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class BrandingExtension extends AbstractExtension
{
    public function __construct(
        private RequestStack $requestStack,
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenStorage
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_brand_name', [$this, 'getBrandName']),
            new TwigFunction('get_brand_logo', [$this, 'getBrandLogo']),
            new TwigFunction('get_reseller_owner', [$this, 'getResellerOwner']),
            new TwigFunction('get_helper_domain', [$this, 'getHelperDomain']),
            new TwigFunction('get_custom_css', [$this, 'getCustomCss']),
        ];
    }

    private function getBranding(): ?ResellerBranding
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $branding = $request->attributes->get('_reseller_branding');
            if ($branding) {
                return $branding;
            }
        }

        // Fallback to logged-in user context if token exists
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();
        if ($user instanceof Admin) {
            $owner = $user;
            if (in_array($user->getAccountType(), ['team', 'user']) && $user->getParent() !== null) {
                $owner = $user->getParent();
            }
            return $owner->getBranding();
        }

        return null;
    }

    public function getBrandName(string $default = 'OpenSquadron'): string
    {
        $branding = $this->getBranding();
        if ($branding && $branding->getBrandName()) {
            return $branding->getBrandName();
        }
        return $default;
    }

    public function getBrandLogo(): ?string
    {
        $branding = $this->getBranding();
        if ($branding && $branding->getLogoUrl()) {
            return $branding->getLogoUrl();
        }
        return null;
    }

    public function getResellerOwner(): ?Admin
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $owner = $request->attributes->get('_reseller_owner');
            if ($owner) {
                return $owner;
            }
        }

        // Fallback to logged-in user context if token exists
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();
        if ($user instanceof Admin) {
            $owner = $user;
            if (in_array($user->getAccountType(), ['team', 'user']) && $user->getParent() !== null) {
                $owner = $user->getParent();
            }
            return $owner;
        }

        return null;
    }

    public function getHelperDomain(): string
    {
        // Try to get super admin
        $superAdmin = $this->entityManager->getRepository(Admin::class)->findOneBy(['accountType' => 'super_admin']);
        if ($superAdmin && $superAdmin->getCloudflareSettings() && $superAdmin->getCloudflareSettings()->getHelperDomain()) {
            return $superAdmin->getCloudflareSettings()->getHelperDomain();
        }
        
        return '[pending-cloudflare-helper-domain]';
    }

    public function getCustomCss(): ?string
    {
        $branding = $this->getBranding();
        if ($branding) {
            return $branding->getCustomCss();
        }
        return null;
    }
}
