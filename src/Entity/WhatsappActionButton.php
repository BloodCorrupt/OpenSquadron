<?php
 
namespace App\Entity;
 
use App\Repository\WhatsappActionButtonRepository;
use Doctrine\ORM\Mapping as ORM;
 
use App\Entity\TenantAwareInterface;
use App\Entity\Admin;
use App\Entity\WhatsAppConnection;
use App\Entity\WhatsappBotFlow;
 
#[ORM\Entity(repositoryClass: WhatsappActionButtonRepository::class)]
class WhatsappActionButton implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: "owner_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?Admin $owner = null;

    #[ORM\ManyToOne(targetEntity: WhatsAppConnection::class)]
    #[ORM\JoinColumn(name: "whats_app_connection_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?WhatsAppConnection $whatsAppConnection = null;

    #[ORM\Column(length: 50)]
    private ?string $buttonKey = null;

    #[ORM\Column(length: 100)]
    private ?string $buttonLabel = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isEnabled = false;

    #[ORM\Column(length: 20, options: ['default' => 'none'])]
    private string $replyType = 'none';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $replyText = null;

    #[ORM\ManyToOne(targetEntity: WhatsappBotFlow::class)]
    #[ORM\JoinColumn(name: "whats_app_bot_flow_id", referencedColumnName: "id", onDelete: "SET NULL")]
    private ?WhatsappBotFlow $botFlow = null;

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

    public function getButtonKey(): ?string
    {
        return $this->buttonKey;
    }

    public function setButtonKey(string $buttonKey): static
    {
        $this->buttonKey = $buttonKey;
        return $this;
    }

    public function getButtonLabel(): ?string
    {
        return $this->buttonLabel;
    }

    public function setButtonLabel(string $buttonLabel): static
    {
        $this->buttonLabel = $buttonLabel;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): static
    {
        $this->isEnabled = $isEnabled;
        return $this;
    }

    public function getReplyType(): string
    {
        return $this->replyType;
    }

    public function setReplyType(string $replyType): static
    {
        $this->replyType = $replyType;
        return $this;
    }

    public function getReplyText(): ?string
    {
        return $this->replyText;
    }

    public function setReplyText(?string $replyText): static
    {
        $this->replyText = $replyText;
        return $this;
    }

    public function getBotFlow(): ?WhatsappBotFlow
    {
        return $this->botFlow;
    }

    public function setBotFlow(?WhatsappBotFlow $botFlow): static
    {
        $this->botFlow = $botFlow;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'buttonKey' => $this->buttonKey,
            'buttonLabel' => $this->buttonLabel,
            'isEnabled' => $this->isEnabled,
            'replyType' => $this->replyType,
            'replyText' => $this->replyText,
            'flowId' => $this->botFlow ? $this->botFlow->getId() : null,
        ];
    }
}
