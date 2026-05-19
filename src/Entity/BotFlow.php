<?php

namespace App\Entity;

use App\Repository\BotFlowRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BotFlowRepository::class)]
class BotFlow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $triggerKeyword = null;

    #[ORM\Column(type: 'json')]
    private array $flowData = [];

    #[ORM\Column]
    private ?bool $isActive = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTriggerKeyword(): ?string
    {
        return $this->triggerKeyword;
    }

    public function setTriggerKeyword(string $triggerKeyword): static
    {
        $this->triggerKeyword = $triggerKeyword;

        return $this;
    }

    public function getFlowData(): array
    {
        return $this->flowData;
    }

    public function setFlowData(array $flowData): static
    {
        $this->flowData = $flowData;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }
}
