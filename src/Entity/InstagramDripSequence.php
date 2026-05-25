<?php

namespace App\Entity;

use App\Repository\InstagramDripSequenceRepository;
use Doctrine\ORM\Mapping as ORM;

use App\Entity\TenantAwareInterface;
use App\Entity\Admin;
use App\Entity\InstagramConnection;

#[ORM\Entity(repositoryClass: InstagramDripSequenceRepository::class)]
class InstagramDripSequence implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: "owner_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?Admin $owner = null;

    #[ORM\ManyToOne(targetEntity: InstagramConnection::class)]
    #[ORM\JoinColumn(name: "Instagram_connection_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?InstagramConnection $InstagramConnection = null;

    #[ORM\Column(length: 200)]
    private ?string $name = 'Untitled Sequence';

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

    public function getInstagramConnection(): ?InstagramConnection
    {
        return $this->InstagramConnection;
    }

    public function setInstagramConnection(?InstagramConnection $InstagramConnection): static
    {
        $this->InstagramConnection = $InstagramConnection;
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

