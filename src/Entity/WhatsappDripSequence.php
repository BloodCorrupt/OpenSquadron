<?php

namespace App\Entity;

use App\Repository\WhatsappDripSequenceRepository;
use Doctrine\ORM\Mapping as ORM;

use App\Entity\TenantAwareInterface;
use App\Entity\Admin;
use App\Entity\WhatsAppConnection;

#[ORM\Entity(repositoryClass: WhatsappDripSequenceRepository::class)]
#[ORM\Table(name: 'whatsapp_drip_sequence')]
class WhatsappDripSequence implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: "owner_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?Admin $owner = null;

    #[ORM\ManyToOne(targetEntity: WhatsAppConnection::class)]
    #[ORM\JoinColumn(name: "whatsapp_connection_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?WhatsAppConnection $whatsAppConnection = null;

    #[ORM\Column(length: 200)]
    private ?string $name = 'Untitled Sequence';

    /**
     * WhatsApp-specific trigger type (e.g. NEW_SUBSCRIBER, INACTIVE_30_DAYS).
     */
    #[ORM\Column(name: '`trigger`', length: 60, options: ['default' => 'NEW_SUBSCRIBER'])]
    private string $trigger = 'NEW_SUBSCRIBER';

    #[ORM\Column(length: 30, options: ['default' => 'anytime'])]
    private string $preferredTime = 'anytime';

    #[ORM\Column(length: 50, options: ['default' => 'UTC'])]
    private string $timezone = 'UTC';

    #[ORM\Column(length: 60, options: ['default' => 'NON_PROMOTIONAL_SUBSCRIPTION'])]
    private string $messageTag = 'NON_PROMOTIONAL_SUBSCRIPTION';

    #[ORM\Column(options: ['default' => false])]
    private bool $allowReentry = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(options: ['default' => 0])]
    private int $stepsCount = 0;

    /**
     * Full graph payload: {nodes:[], edges:[], viewport:{}}
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $graphData = null;

    // ── Getters / Setters ──────────────────────────────────

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

    public function getWhatsAppConnection(): ?WhatsAppConnection
    {
        return $this->whatsAppConnection;
    }

    public function setWhatsAppConnection(?WhatsAppConnection $whatsAppConnection): static
    {
        $this->whatsAppConnection = $whatsAppConnection;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getTrigger(): string
    {
        return $this->trigger;
    }

    public function setTrigger(string $trigger): static
    {
        $this->trigger = $trigger;
        return $this;
    }

    public function getPreferredTime(): string
    {
        return $this->preferredTime;
    }

    public function setPreferredTime(string $preferredTime): static
    {
        $this->preferredTime = $preferredTime;
        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;
        return $this;
    }

    public function getMessageTag(): string
    {
        return $this->messageTag;
    }

    public function setMessageTag(string $messageTag): static
    {
        $this->messageTag = $messageTag;
        return $this;
    }

    public function isAllowReentry(): bool
    {
        return $this->allowReentry;
    }

    public function setAllowReentry(bool $allowReentry): static
    {
        $this->allowReentry = $allowReentry;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getStepsCount(): int
    {
        return $this->stepsCount;
    }

    public function setStepsCount(int $stepsCount): static
    {
        $this->stepsCount = $stepsCount;
        return $this;
    }

    public function getGraphData(): ?array
    {
        return $this->graphData;
    }

    public function setGraphData(?array $graphData): static
    {
        $this->graphData = $graphData;
        return $this;
    }

    /**
     * Serialize to the array format previously used in the JSON file.
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'trigger'       => $this->trigger,
            'preferredTime' => $this->preferredTime,
            'timezone'      => $this->timezone,
            'messageTag'    => $this->messageTag,
            'allowReentry'  => $this->allowReentry,
            'isActive'      => $this->isActive,
            'stepsCount'    => $this->stepsCount,
            'graph'         => $this->graphData,
        ];
    }
}
