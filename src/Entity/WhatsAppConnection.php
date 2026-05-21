<?php

namespace App\Entity;

use App\Repository\WhatsAppConnectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use App\Entity\TenantAwareInterface;
use App\Entity\Admin;

#[ORM\Entity(repositoryClass: WhatsAppConnectionRepository::class)]
#[ORM\Table(name: 'whatsapp_connection')]
#[ORM\HasLifecycleCallbacks]
class WhatsAppConnection implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: "owner_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?Admin $owner = null;

    #[ORM\Column(length: 255)]
    private ?string $businessAccountId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $phoneNumberId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $encryptedAccessToken = null;

    #[ORM\Column(length: 64)]
    private ?string $verifyToken = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $webhookUrl = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'active';

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $aiActive = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $agentName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $agentRole = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contextData = null;

    #[ORM\ManyToOne(targetEntity: AiContext::class)]
    #[ORM\JoinColumn(name: "active_context_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?AiContext $activeContext = null;

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

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): static
    {
        $this->phoneNumber = $phoneNumber;
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

    public function isAiActive(): bool
    {
        return $this->aiActive;
    }

    public function setAiActive(bool $aiActive): static
    {
        $this->aiActive = $aiActive;
        return $this;
    }

    public function getAgentName(): ?string
    {
        return $this->agentName;
    }

    public function setAgentName(?string $agentName): static
    {
        $this->agentName = $agentName;
        return $this;
    }

    public function getAgentRole(): ?string
    {
        return $this->agentRole;
    }

    public function setAgentRole(?string $agentRole): static
    {
        $this->agentRole = $agentRole;
        return $this;
    }

    public function getContextData(): ?string
    {
        return $this->contextData;
    }

    public function setContextData(?string $contextData): static
    {
        $this->contextData = $contextData;
        return $this;
    }

    public function getActiveContext(): ?AiContext
    {
        return $this->activeContext;
    }

    public function setActiveContext(?AiContext $activeContext): static
    {
        $this->activeContext = $activeContext;
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

    public function getOwner(): ?Admin
    {
        return $this->owner;
    }

    public function setOwner(?Admin $owner): static
    {
        $this->owner = $owner;
        return $this;
    }
}
