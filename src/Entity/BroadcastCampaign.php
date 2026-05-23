<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'broadcast_campaigns')]
class BroadcastCampaign
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Admin $owner = null;

    #[ORM\ManyToOne(targetEntity: WhatsAppConnection::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WhatsAppConnection $connection = null;

    #[ORM\Column(length: 255)]
    private ?string $campaignName = null;

    #[ORM\Column(length: 50)]
    private ?string $broadcastType = '24_hours';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $templateName = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $audienceFilters = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $assignLabelAfter = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $scheduledAt = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'SCHEDULED';

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $processedCount = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $deliveredCount = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $openedCount = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $unreachedCount = 0;

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

    public function getConnection(): ?WhatsAppConnection
    {
        return $this->connection;
    }

    public function setConnection(?WhatsAppConnection $connection): static
    {
        $this->connection = $connection;
        return $this;
    }

    public function getCampaignName(): ?string
    {
        return $this->campaignName;
    }

    public function setCampaignName(string $campaignName): static
    {
        $this->campaignName = $campaignName;
        return $this;
    }

    public function getBroadcastType(): ?string
    {
        return $this->broadcastType;
    }

    public function setBroadcastType(string $broadcastType): static
    {
        $this->broadcastType = $broadcastType;
        return $this;
    }

    public function getTemplateName(): ?string
    {
        return $this->templateName;
    }

    public function setTemplateName(?string $templateName): static
    {
        $this->templateName = $templateName;
        return $this;
    }

    public function getAudienceFilters(): ?array
    {
        return $this->audienceFilters;
    }

    public function setAudienceFilters(?array $audienceFilters): static
    {
        $this->audienceFilters = $audienceFilters;
        return $this;
    }

    public function getAssignLabelAfter(): ?array
    {
        return $this->assignLabelAfter;
    }

    public function setAssignLabelAfter(?array $assignLabelAfter): static
    {
        $this->assignLabelAfter = $assignLabelAfter;
        return $this;
    }

    public function getScheduledAt(): ?string
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?string $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;
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

    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    public function setProcessedCount(int $processedCount): static
    {
        $this->processedCount = $processedCount;
        return $this;
    }

    public function getDeliveredCount(): int
    {
        return $this->deliveredCount;
    }

    public function setDeliveredCount(int $deliveredCount): static
    {
        $this->deliveredCount = $deliveredCount;
        return $this;
    }

    public function getOpenedCount(): int
    {
        return $this->openedCount;
    }

    public function setOpenedCount(int $openedCount): static
    {
        $this->openedCount = $openedCount;
        return $this;
    }

    public function getUnreachedCount(): int
    {
        return $this->unreachedCount;
    }

    public function setUnreachedCount(int $unreachedCount): static
    {
        $this->unreachedCount = $unreachedCount;
        return $this;
    }
}
