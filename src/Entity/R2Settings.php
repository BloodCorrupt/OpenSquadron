<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class R2Settings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'r2Settings', targetEntity: Admin::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Admin $owner = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $accountId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $accessKeyId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $secretAccessKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $bucketName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publicUrl = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $useCustom = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $inboxRetentionDays = null;

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

    public function getAccountId(): ?string
    {
        return $this->accountId;
    }

    public function setAccountId(?string $accountId): static
    {
        $this->accountId = $accountId;
        return $this;
    }

    public function getAccessKeyId(): ?string
    {
        return $this->accessKeyId;
    }

    public function setAccessKeyId(?string $accessKeyId): static
    {
        $this->accessKeyId = $accessKeyId;
        return $this;
    }

    public function getSecretAccessKey(): ?string
    {
        return $this->secretAccessKey;
    }

    public function setSecretAccessKey(?string $secretAccessKey): static
    {
        $this->secretAccessKey = $secretAccessKey;
        return $this;
    }

    public function getBucketName(): ?string
    {
        return $this->bucketName;
    }

    public function setBucketName(?string $bucketName): static
    {
        $this->bucketName = $bucketName;
        return $this;
    }

    public function getPublicUrl(): ?string
    {
        return $this->publicUrl;
    }

    public function setPublicUrl(?string $publicUrl): static
    {
        if ($publicUrl !== null) {
            $publicUrl = rtrim($publicUrl, '/');
        }
        $this->publicUrl = $publicUrl;
        return $this;
    }

    public function isUseCustom(): bool
    {
        return $this->useCustom;
    }

    public function setUseCustom(bool $useCustom): static
    {
        $this->useCustom = $useCustom;
        return $this;
    }

    public function getInboxRetentionDays(): ?int
    {
        return $this->inboxRetentionDays;
    }

    public function setInboxRetentionDays(?int $inboxRetentionDays): static
    {
        $this->inboxRetentionDays = $inboxRetentionDays;
        return $this;
    }
}
