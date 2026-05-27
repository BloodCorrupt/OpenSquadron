<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use App\Entity\TenantAwareInterface;
use App\Entity\Admin;

#[ORM\Entity]
#[ORM\Table(name: 'ai_context')]
class AiContext implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: "owner_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?Admin $owner = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'active';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $modulesData = [];

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $agentRole = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $systemInstruction = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contextData = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = false;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getModulesData(): ?array
    {
        return $this->modulesData;
    }

    public function setModulesData(?array $modulesData): static
    {
        $this->modulesData = $modulesData;
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

    public function getAgentRole(): ?string
    {
        return $this->agentRole;
    }

    public function setAgentRole(?string $agentRole): static
    {
        $this->agentRole = $agentRole;
        return $this;
    }

    public function getSystemInstruction(): ?string
    {
        return $this->systemInstruction;
    }

    public function setSystemInstruction(?string $systemInstruction): static
    {
        $this->systemInstruction = $systemInstruction;
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $isActive): static
    {
        $this->isActive = $isActive;
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
}
