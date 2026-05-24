<?php

namespace App\Twig;

use App\Entity\Admin;
use App\Entity\ResellerBranding;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use Doctrine\ORM\EntityManagerInterface;

class BrandingExtension extends AbstractExtension
{
    public function __construct(
        private RequestStack $requestStack,
        private EntityManagerInterface $entityManager
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_brand_name', [$this, 'getBrandName']),
            new TwigFunction('get_brand_logo', [$this, 'getBrandLogo']),
            new TwigFunction('get_reseller_owner', [$this, 'getResellerOwner']),
            new TwigFunction('get_helper_domain', [$this, 'getHelperDomain']),
        ];
    }

    private function getBranding(): ?ResellerBranding
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            return $request->attributes->get('_reseller_branding');
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
            return $request->attributes->get('_reseller_owner');
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
}
