<?php

namespace App\Entity;

use App\Repository\SubscriberRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use App\Entity\TenantAwareInterface;
use App\Entity\Admin;
use App\Entity\WhatsappBotFlow;
use App\Entity\FacebookBotFlow;
use App\Entity\FacebookConnection;

#[ORM\Entity(repositoryClass: SubscriberRepository::class)]
#[ORM\UniqueConstraint(name: "uniq_subscriber_phone_connection", columns: ["phone_number", "whats_app_connection_id"])]
#[ORM\UniqueConstraint(name: "uniq_subscriber_facebook_connection", columns: ["psid", "facebook_connection_id"])]
class Subscriber implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: "owner_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?Admin $owner = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column(length: 20)]
    private ?string $channel = 'whatsapp';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $psid = null;

    #[ORM\ManyToOne(targetEntity: WhatsAppConnection::class)]
    #[ORM\JoinColumn(name: "whats_app_connection_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?WhatsAppConnection $whatsAppConnection = null;

    #[ORM\ManyToOne(targetEntity: FacebookConnection::class)]
    #[ORM\JoinColumn(name: "facebook_connection_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?FacebookConnection $facebookConnection = null;

    #[ORM\ManyToOne(targetEntity: InstagramConnection::class)]
    #[ORM\JoinColumn(name: "instagram_connection_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?InstagramConnection $instagramConnection = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 20)]
    private ?string $status = 'active';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'subscriber', orphanRemoval: true)]
    private Collection $messages;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: "assigned_operator_id", referencedColumnName: "id", onDelete: "SET NULL")]
    private ?Admin $assignedOperator = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $tags = [];

    #[ORM\ManyToOne(targetEntity: WhatsappBotFlow::class)]
    #[ORM\JoinColumn(name: 'assigned_whatsapp_flow_id', referencedColumnName: 'id')]
    private ?WhatsappBotFlow $assignedWhatsappFlow = null;

    #[ORM\ManyToOne(targetEntity: FacebookBotFlow::class)]
    #[ORM\JoinColumn(name: "assigned_facebook_flow_id", referencedColumnName: "id", onDelete: "SET NULL")]
    private ?FacebookBotFlow $assignedFacebookFlow = null;

    #[ORM\ManyToOne(targetEntity: \App\Entity\InstagramBotFlow::class)]
    #[ORM\JoinColumn(name: "assigned_instagram_flow_id", referencedColumnName: "id", onDelete: "SET NULL")]
    private ?\App\Entity\InstagramBotFlow $assignedInstagramFlow = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $customAttributes = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $notes = [];

    public function __construct()
    {
        $this->messages = new ArrayCollection();
        $this->createdAt = new \DateTime('now', new \DateTimeZone('UTC'));
        $this->updatedAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): static
    {
        $this->phoneNumber = $phoneNumber;
        return $this;
    }

    public function getChannel(): ?string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): static
    {
        $this->channel = $channel;
        return $this;
    }

    public function getPsid(): ?string
    {
        return $this->psid;
    }

    public function setPsid(?string $psid): static
    {
        $this->psid = $psid;
        return $this;
    }

    public function getFacebookConnection(): ?FacebookConnection
    {
        return $this->facebookConnection;
    }

    public function setFacebookConnection(?FacebookConnection $facebookConnection): static
    {
        $this->facebookConnection = $facebookConnection;
        return $this;
    }

    public function getInstagramConnection(): ?InstagramConnection
    {
        return $this->instagramConnection;
    }

    public function setInstagramConnection(?InstagramConnection $instagramConnection): static
    {
        $this->instagramConnection = $instagramConnection;
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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        if ($this->createdAt === null) {
            return null;
        }
        return new \DateTime($this->createdAt->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        if ($createdAt instanceof \DateTime) {
            $utc = clone $createdAt;
            $utc->setTimezone(new \DateTimeZone('UTC'));
            $this->createdAt = $utc;
        } else {
            $this->createdAt = $createdAt;
        }
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        if ($this->updatedAt === null) {
            return null;
        }
        return new \DateTime($this->updatedAt->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        if ($updatedAt instanceof \DateTime) {
            $utc = clone $updatedAt;
            $utc->setTimezone(new \DateTimeZone('UTC'));
            $this->updatedAt = $utc;
        } else {
            $this->updatedAt = $updatedAt;
        }
        return $this;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setSubscriber($this);
        }

        return $this;
    }

    public function removeMessage(Message $message): static
    {
        if ($this->messages->removeElement($message)) {
            // set the owning side to null (unless already changed)
            if ($message->getSubscriber() === $this) {
                $message->setSubscriber(null);
            }
        }

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

    public function getAssignedOperator(): ?Admin
    {
        return $this->assignedOperator;
    }

    public function setAssignedOperator(?Admin $assignedOperator): static
    {
        $this->assignedOperator = $assignedOperator;
        return $this;
    }

    public function getTags(): array
    {
        return $this->tags ?? [];
    }

    public function setTags(?array $tags): static
    {
        $this->tags = $tags;
        return $this;
    }

    public function getAssignedWhatsappFlow(): ?WhatsappBotFlow
    {
        return $this->assignedWhatsappFlow;
    }

    public function setAssignedWhatsappFlow(?WhatsappBotFlow $assignedWhatsappFlow): static
    {
        $this->assignedWhatsappFlow = $assignedWhatsappFlow;

        return $this;
    }

    public function getAssignedFacebookFlow(): ?FacebookBotFlow
    {
        return $this->assignedFacebookFlow;
    }

    public function setAssignedFacebookFlow(?FacebookBotFlow $assignedFacebookFlow): static
    {
        $this->assignedFacebookFlow = $assignedFacebookFlow;
        return $this;
    }

    public function getAssignedInstagramFlow(): ?\App\Entity\InstagramBotFlow
    {
        return $this->assignedInstagramFlow;
    }

    public function setAssignedInstagramFlow(?\App\Entity\InstagramBotFlow $assignedInstagramFlow): static
    {
        $this->assignedInstagramFlow = $assignedInstagramFlow;
        return $this;
    }

    public function getCustomAttributes(): array
    {
        return $this->customAttributes ?? [];
    }

    public function setCustomAttributes(?array $customAttributes): static
    {
        $this->customAttributes = $customAttributes;
        return $this;
    }

    public function getNotes(): array
    {
        return $this->notes ?? [];
    }

    public function setNotes(?array $notes): static
    {
        $this->notes = $notes;
        return $this;
    }
}
