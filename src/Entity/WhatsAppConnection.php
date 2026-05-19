<?php

namespace App\Entity;

use App\Repository\WhatsAppConnectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WhatsAppConnectionRepository::class)]
#[ORM\Table(name: 'whatsapp_connection')]
#[ORM\HasLifecycleCallbacks]
class WhatsAppConnection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $businessAccountId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $phoneNumberId = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $encryptedAccessToken = null;

    #[ORM\Column(length: 64)]
    private ?string $verifyToken = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $webhookUrl = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'active';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBusinessAccountId(): ?string
    {
        return $this->businessAccountId;
    }

    public function setBusinessAccountId(string $businessAccountId): static
    {
        $this->businessAccountId = $businessAccountId;

        return $this;
    }

    public function getPhoneNumberId(): ?string
    {
        return $this->phoneNumberId;
    }

    public function setPhoneNumberId(?string $phoneNumberId): static
    {
        $this->phoneNumberId = $phoneNumberId;

        return $this;
    }

    public function getEncryptedAccessToken(): ?string
    {
        return $this->encryptedAccessToken;
    }

    public function setEncryptedAccessToken(string $encryptedAccessToken): static
    {
        $this->encryptedAccessToken = $encryptedAccessToken;

        return $this;
    }

    public function getVerifyToken(): ?string
    {
        return $this->verifyToken;
    }

    public function setVerifyToken(string $verifyToken): static
    {
        $this->verifyToken = $verifyToken;

        return $this;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function setWebhookUrl(?string $webhookUrl): static
    {
        $this->webhookUrl = $webhookUrl;

        return $this;
    }

    public function getStatus(): ?string
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

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime();
    }
}
