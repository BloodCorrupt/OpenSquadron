<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\EcomSettingRepository;

#[ORM\Entity(repositoryClass: EcomSettingRepository::class)]
class EcomSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Admin $owner = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $paymentInstructions = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $checkoutEnabled = true;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $globalExternalUrl = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?Admin
    {
        return $this->owner;
    }

    public function setOwner(Admin $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    public function getPaymentInstructions(): ?string
    {
        return $this->paymentInstructions;
    }

    public function setPaymentInstructions(?string $paymentInstructions): static
    {
        $this->paymentInstructions = $paymentInstructions;
        return $this;
    }

    public function isCheckoutEnabled(): bool
    {
        return $this->checkoutEnabled;
    }

    public function setCheckoutEnabled(bool $checkoutEnabled): static
    {
        $this->checkoutEnabled = $checkoutEnabled;
        return $this;
    }

    public function getGlobalExternalUrl(): ?string
    {
        return $this->globalExternalUrl;
    }

    public function setGlobalExternalUrl(?string $globalExternalUrl): static
    {
        $this->globalExternalUrl = $globalExternalUrl;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'paymentInstructions' => $this->getPaymentInstructions(),
            'checkoutEnabled' => $this->isCheckoutEnabled(),
            'globalExternalUrl' => $this->getGlobalExternalUrl(),
        ];
    }
}
