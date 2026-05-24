<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class CloudflareSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'cloudflareSettings', targetEntity: Admin::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Admin $owner = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $helperDomain = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $apiToken = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $zoneId = null;

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

    public function getHelperDomain(): ?string
    {
        return $this->helperDomain;
    }

    public function setHelperDomain(?string $helperDomain): static
    {
        $this->helperDomain = $helperDomain;

        return $this;
    }

    public function getApiToken(): ?string
    {
        return $this->apiToken;
    }

    public function setApiToken(?string $apiToken): static
    {
        $this->apiToken = $apiToken;

        return $this;
    }

    public function getZoneId(): ?string
    {
        return $this->zoneId;
    }

    public function setZoneId(?string $zoneId): static
    {
        $this->zoneId = $zoneId;

        return $this;
    }
}
