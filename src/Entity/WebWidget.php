<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'web_widgets')]
class WebWidget
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
    private ?string $widgetName = null;

    #[ORM\Column(length: 50)]
    private ?string $widgetType = 'floating_bubble';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $customization = null;

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

    public function getWidgetName(): ?string
    {
        return $this->widgetName;
    }

    public function setWidgetName(string $widgetName): static
    {
        $this->widgetName = $widgetName;
        return $this;
    }

    public function getWidgetType(): ?string
    {
        return $this->widgetType;
    }

    public function setWidgetType(string $widgetType): static
    {
        $this->widgetType = $widgetType;
        return $this;
    }

    public function getCustomization(): ?array
    {
        return $this->customization;
    }

    public function setCustomization(?array $customization): static
    {
        $this->customization = $customization;
        return $this;
    }
}
