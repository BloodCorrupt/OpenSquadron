<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ResellerBranding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'branding', targetEntity: Admin::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Admin $owner = null;

    #[ORM\Column(length: 255, nullable: true, unique: true)]
    private ?string $customDomain = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $brandName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cloudflareHostnameId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sslValidationName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sslValidationValue = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $sslStatus = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?Admin
    {
        return $this->owner;
    }

    public function setOwner(?Admin $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    public function getCustomDomain(): ?string
    {
        return $this->customDomain;
    }

    public function setCustomDomain(?string $customDomain): static
    {
        $this->customDomain = $customDomain;
        return $this;
    }

    public function getBrandName(): ?string
    {
        return $this->brandName;
    }

    public function setBrandName(?string $brandName): static
    {
        $this->brandName = $brandName;
        return $this;
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function setLogoUrl(?string $logoUrl): static
    {
        $this->logoUrl = $logoUrl;

        return $this;
    }

    public function getCloudflareHostnameId(): ?string
    {
        return $this->cloudflareHostnameId;
    }

    public function setCloudflareHostnameId(?string $cloudflareHostnameId): static
    {
        $this->cloudflareHostnameId = $cloudflareHostnameId;

        return $this;
    }

    public function getSslValidationName(): ?string
    {
        return $this->sslValidationName;
    }

    public function setSslValidationName(?string $sslValidationName): static
    {
        $this->sslValidationName = $sslValidationName;

        return $this;
    }

    public function getSslValidationValue(): ?string
    {
        return $this->sslValidationValue;
    }

    public function setSslValidationValue(?string $sslValidationValue): static
    {
        $this->sslValidationValue = $sslValidationValue;

        return $this;
    }

    public function getSslStatus(): ?string
    {
        return $this->sslStatus;
    }

    public function setSslStatus(?string $sslStatus): static
    {
        $this->sslStatus = $sslStatus;

        return $this;
    }
}
