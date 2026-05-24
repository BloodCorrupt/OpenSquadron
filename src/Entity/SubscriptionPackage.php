<?php

namespace App\Entity;

use App\Repository\SubscriptionPackageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionPackageRepository::class)]
class SubscriptionPackage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Admin $owner = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column]
    private ?float $price = 0.0;

    #[ORM\Column]
    private ?int $validityDays = 30;

    #[ORM\Column(options: ['default' => false])]
    private bool $isResellerPackage = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isDefault = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $isLifetime = false;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $features = null;

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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(float $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getValidityDays(): ?int
    {
        return $this->validityDays;
    }

    public function setValidityDays(int $validityDays): static
    {
        $this->validityDays = $validityDays;

        return $this;
    }

    public function isResellerPackage(): bool
    {
        return $this->isResellerPackage;
    }

    public function setResellerPackage(bool $isResellerPackage): static
    {
        $this->isResellerPackage = $isResellerPackage;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function isLifetime(): bool
    {
        return $this->isLifetime;
    }

    public function setLifetime(bool $isLifetime): static
    {
        $this->isLifetime = $isLifetime;

        return $this;
    }

    public function getFeatures(): ?array
    {
        return $this->features;
    }

    public function setFeatures(?array $features): static
    {
        $this->features = $features;

        return $this;
    }
}
