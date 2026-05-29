<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use App\Entity\TenantAwareInterface;
use App\Entity\Admin;

#[ORM\Entity]
#[ORM\Table(name: 'meta_setting')]
#[ORM\HasLifecycleCallbacks]
class MetaSetting implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: "owner_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?Admin $owner = null;

    #[ORM\Column(length: 255)]
    private ?string $appId = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $encryptedAppSecret = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $verifyToken = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $whatsappConfigId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $appName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $systemUserAccessToken = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $whatsappAppId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $whatsappEncryptedAppSecret = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $whatsappVerifyToken = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAppId(): ?string
    {
        return $this->appId;
    }

    public function setAppId(string $appId): static
    {
        $this->appId = $appId;
        return $this;
    }

    public function getEncryptedAppSecret(): ?string
    {
        return $this->encryptedAppSecret;
    }

    public function setEncryptedAppSecret(string $encryptedAppSecret): static
    {
        $this->encryptedAppSecret = $encryptedAppSecret;
        return $this;
    }

    public function getVerifyToken(): ?string
    {
        return $this->verifyToken;
    }

    public function setVerifyToken(?string $verifyToken): static
    {
        $this->verifyToken = $verifyToken;
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
        $this->createdAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }

    public function getWhatsappConfigId(): ?string
    {
        return $this->whatsappConfigId;
    }

    public function setWhatsappConfigId(?string $whatsappConfigId): static
    {
        $this->whatsappConfigId = $whatsappConfigId;
        return $this;
    }

    public function getAppName(): ?string
    {
        return $this->appName;
    }

    public function setAppName(?string $appName): static
    {
        $this->appName = $appName;
        return $this;
    }

    public function getSystemUserAccessToken(): ?string
    {
        return $this->systemUserAccessToken;
    }

    public function setSystemUserAccessToken(?string $systemUserAccessToken): static
    {
        $this->systemUserAccessToken = $systemUserAccessToken;
        return $this;
    }

    public function getWhatsappAppId(): ?string
    {
        return $this->whatsappAppId;
    }

    public function setWhatsappAppId(?string $whatsappAppId): static
    {
        $this->whatsappAppId = $whatsappAppId;
        return $this;
    }

    public function getWhatsappEncryptedAppSecret(): ?string
    {
        return $this->whatsappEncryptedAppSecret;
    }

    public function setWhatsappEncryptedAppSecret(?string $whatsappEncryptedAppSecret): static
    {
        $this->whatsappEncryptedAppSecret = $whatsappEncryptedAppSecret;
        return $this;
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

    public function getWhatsappVerifyToken(): ?string
    {
        return $this->whatsappVerifyToken;
    }

    public function setWhatsappVerifyToken(?string $whatsappVerifyToken): static
    {
        $this->whatsappVerifyToken = $whatsappVerifyToken;

        return $this;
    }
}
