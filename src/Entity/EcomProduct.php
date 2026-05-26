<?php

namespace App\Entity;

use App\Repository\EcomProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use App\Entity\TenantAwareInterface;
use App\Entity\Admin;

#[ORM\Entity(repositoryClass: EcomProductRepository::class)]
#[ORM\Table(name: 'ecom_product')]
#[ORM\HasLifecycleCallbacks]
class EcomProduct implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Admin $owner = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $price = '0.00';

    #[ORM\Column(length: 10)]
    private ?string $currency = 'USD';

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $externalUrl = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $galleryUrls = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $stock = 0;

    #[ORM\Column(length: 20, options: ['default' => 'active'])]
    private string $status = 'active';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    // ───────────────────────── Lifecycle ─────────────────────────

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }

    // ───────────────────────── Getters & Setters ─────────────────────────

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;
        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(?string $sku): static
    {
        $this->sku = $sku;
        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): static
    {
        $this->imageUrl = $imageUrl;
        return $this;
    }

    public function getExternalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function setExternalUrl(?string $externalUrl): static
    {
        $this->externalUrl = $externalUrl;
        return $this;
    }

    public function getGalleryUrls(): ?array
    {
        return $this->galleryUrls;
    }

    public function setGalleryUrls(?array $galleryUrls): static
    {
        $this->galleryUrls = $galleryUrls;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function setStock(int $stock): static
    {
        $this->stock = $stock;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Return a serializable array for API responses.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'currency' => $this->currency,
            'sku' => $this->sku,
            'imageUrl' => $this->imageUrl,
            'externalUrl' => $this->externalUrl,
            'galleryUrls' => $this->galleryUrls ?? [],
            'category' => $this->category,
            'stock' => $this->stock,
            'status' => $this->status,
            'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
