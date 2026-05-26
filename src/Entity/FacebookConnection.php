<?php

namespace App\Entity;

use App\Repository\FacebookConnectionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use App\Entity\TenantAwareInterface;
use App\Entity\Admin;

#[ORM\Entity(repositoryClass: FacebookConnectionRepository::class)]
#[ORM\Table(name: 'facebook_connection')]
#[ORM\HasLifecycleCallbacks]
class FacebookConnection implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class, inversedBy: 'facebookConnections')]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ?Admin $owner = null;

    #[ORM\Column(length: 255)]
    private ?string $pageId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $botSettings = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $postsCache = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $repliedCommentsCache = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pageName = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $encryptedPageAccessToken = null;

    #[ORM\Column(length: 255)]
    private ?string $appId = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $encryptedAppSecret = null;

    #[ORM\Column(length: 64)]
    private ?string $verifyToken = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $webhookUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'active';

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $ecomContextEnabled = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $aiActive = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $agentName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $agentRole = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contextData = null;

    #[ORM\ManyToOne(targetEntity: AiContext::class)]
    #[ORM\JoinColumn(name: "active_context_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?AiContext $activeContext = null;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, FacebookCommentAutomation>
     */
    #[ORM\OneToMany(mappedBy: 'facebookConnection', targetEntity: FacebookCommentAutomation::class, orphanRemoval: true)]
    private \Doctrine\Common\Collections\Collection $commentAutomations;

    public function __construct()
    {
        $this->commentAutomations = new \Doctrine\Common\Collections\ArrayCollection();
    }

    // ───────────────────────── Getters & Setters ─────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPageId(): ?string
    {
        return $this->pageId;
    }

    public function setPageId(string $pageId): static
    {
        $this->pageId = $pageId;
        return $this;
    }

    public function getPageName(): ?string
    {
        return $this->pageName;
    }

    public function setPageName(?string $pageName): static
    {
        $this->pageName = $pageName;
        return $this;
    }

    public function getEncryptedPageAccessToken(): ?string
    {
        return $this->encryptedPageAccessToken;
    }

    public function setEncryptedPageAccessToken(string $encryptedPageAccessToken): static
    {
        $this->encryptedPageAccessToken = $encryptedPageAccessToken;
        return $this;
    }

    public function getAppId(): ?string
    {
        return $this->appId;
    }

    public function setAppId(string $appId): static
    {
        $this->appId = $appId;
        return $this;
    }

    public function getEncryptedAppSecret(): ?string
    {
        return $this->encryptedAppSecret;
    }

    public function setEncryptedAppSecret(string $encryptedAppSecret): static
    {
        $this->encryptedAppSecret = $encryptedAppSecret;
        return $this;
    }

    public function getVerifyToken(): ?string
    {
        return $this->verifyToken;
    }

    public function setVerifyToken(string $verifyToken): static
    {
        $this->verifyToken = $verifyToken;
        return $this;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function setWebhookUrl(?string $webhookUrl): static
    {
        $this->webhookUrl = $webhookUrl;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;
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

    public function isEcomContextEnabled(): bool
    {
        return $this->ecomContextEnabled;
    }

    public function setEcomContextEnabled(bool $ecomContextEnabled): static
    {
        $this->ecomContextEnabled = $ecomContextEnabled;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }

    public function getBotSettings(): ?array
    {
        return $this->botSettings;
    }

    public function setBotSettings(?array $botSettings): static
    {
        $this->botSettings = $botSettings;
        return $this;
    }

    public function getPostsCache(): ?array
    {
        return $this->postsCache;
    }

    public function setPostsCache(?array $postsCache): static
    {
        $this->postsCache = $postsCache;
        return $this;
    }

    public function getRepliedCommentsCache(): ?array
    {
        return $this->repliedCommentsCache;
    }

    public function setRepliedCommentsCache(?array $repliedCommentsCache): static
    {
        $this->repliedCommentsCache = $repliedCommentsCache;
        return $this;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime('now', new \DateTimeZone('UTC'));
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

    public function isAiActive(): bool
    {
        return $this->aiActive;
    }

    public function setAiActive(bool $aiActive): static
    {
        $this->aiActive = $aiActive;
        return $this;
    }

    public function getAgentName(): ?string
    {
        return $this->agentName;
    }

    public function setAgentName(?string $agentName): static
    {
        $this->agentName = $agentName;
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

    public function getContextData(): ?string
    {
        return $this->contextData;
    }

    public function setContextData(?string $contextData): static
    {
        $this->contextData = $contextData;
        return $this;
    }

    public function getActiveContext(): ?AiContext
    {
        return $this->activeContext;
    }

    public function setActiveContext(?AiContext $activeContext): static
    {
        $this->activeContext = $activeContext;
        return $this;
    }
}
