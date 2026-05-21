<?php

namespace App\Entity;

use App\Repository\MessageTemplateRepository;
use Doctrine\ORM\Mapping as ORM;

use App\Entity\TenantAwareInterface;
use App\Entity\Admin;

#[ORM\Entity(repositoryClass: MessageTemplateRepository::class)]
class MessageTemplate implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: "owner_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?Admin $owner = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 10)]
    private ?string $language = null;

    #[ORM\Column(length: 50)]
    private ?string $status = null;

    #[ORM\Column(length: 50)]
    private ?string $category = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $components = null;

    #[ORM\ManyToOne(targetEntity: WhatsAppConnection::class)]
    #[ORM\JoinColumn(name: "whatsapp_connection_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?WhatsAppConnection $whatsAppConnection = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(string $language): static
    {
        $this->language = $language;

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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getComponents(): ?array
    {
        return $this->components;
    }

    public function setComponents(?array $components): static
    {
        $this->components = $components;

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
