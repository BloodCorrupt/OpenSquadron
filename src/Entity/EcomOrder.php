<?php

namespace App\Entity;

use App\Repository\EcomOrderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use App\Entity\TenantAwareInterface;
use App\Entity\Admin;
use App\Entity\Subscriber;

#[ORM\Entity(repositoryClass: EcomOrderRepository::class)]
#[ORM\Table(name: 'ecom_order')]
#[ORM\HasLifecycleCallbacks]
class EcomOrder implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Admin $owner = null;

    #[ORM\Column(length: 50)]
    private ?string $orderNumber = null;

    #[ORM\ManyToOne(targetEntity: Subscriber::class)]
    #[ORM\JoinColumn(name: 'subscriber_id', referencedColumnName: 'id', onDelete: 'SET NULL', nullable: true)]
    private ?Subscriber $subscriber = null;

    #[ORM\Column(length: 255)]
    private ?string $customerName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customerContact = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $channel = null;

    #[ORM\Column(type: 'json')]
    private array $items = [];

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $totalAmount = '0.00';

    #[ORM\Column(length: 10)]
    private ?string $currency = 'USD';

    #[ORM\Column(length: 30, options: ['default' => 'pending'])]
    private string $status = 'pending';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $shippingAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

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

    public function getOrderNumber(): ?string
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(string $orderNumber): static
    {
        $this->orderNumber = $orderNumber;
        return $this;
    }

    public function getSubscriber(): ?Subscriber
    {
        return $this->subscriber;
    }

    public function setSubscriber(?Subscriber $subscriber): static
    {
        $this->subscriber = $subscriber;
        return $this;
    }

    public function getCustomerName(): ?string
    {
        return $this->customerName;
    }

    public function setCustomerName(string $customerName): static
    {
        $this->customerName = $customerName;
        return $this;
    }

    public function getCustomerContact(): ?string
    {
        return $this->customerContact;
    }

    public function setCustomerContact(?string $customerContact): static
    {
        $this->customerContact = $customerContact;
        return $this;
    }

    public function getChannel(): ?string
    {
        return $this->channel;
    }

    public function setChannel(?string $channel): static
    {
        $this->channel = $channel;
        return $this;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function setItems(array $items): static
    {
        $this->items = $items;
        return $this;
    }

    public function getTotalAmount(): ?string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $totalAmount): static
    {
        $this->totalAmount = $totalAmount;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getShippingAddress(): ?string
    {
        return $this->shippingAddress;
    }

    public function setShippingAddress(?string $shippingAddress): static
    {
        $this->shippingAddress = $shippingAddress;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
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
            'orderNumber' => $this->orderNumber,
            'subscriberId' => $this->subscriber?->getId(),
            'customerName' => $this->customerName,
            'customerContact' => $this->customerContact,
            'channel' => $this->channel,
            'items' => $this->items,
            'totalAmount' => $this->totalAmount,
            'currency' => $this->currency,
            'status' => $this->status,
            'shippingAddress' => $this->shippingAddress,
            'notes' => $this->notes,
            'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updatedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
