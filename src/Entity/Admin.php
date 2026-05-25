<?php

namespace App\Entity;

use App\Repository\AdminRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;

#[ORM\Entity(repositoryClass: AdminRepository::class)]
#[ORM\Table(name: '`admin`')]
class Admin implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?Admin $parent = null;

    #[ORM\ManyToOne(targetEntity: TeamRole::class)]
    #[ORM\JoinColumn(name: 'team_role_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?TeamRole $teamRole = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?Admin $createdBy = null;

    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 20, options: ['default' => 'admin'])]
    private string $accountType = 'admin'; // 'super_admin', 'admin', 'user', 'team'

    #[ORM\Column(options: ['default' => false])]
    private bool $teamEnabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\OneToMany(targetEntity: FacebookConnection::class, mappedBy: 'owner', cascade: ['remove'])]
    private Collection $facebookConnections;

    #[ORM\OneToMany(targetEntity: InstagramConnection::class, mappedBy: 'owner', cascade: ['remove'])]
    private Collection $instagramConnections;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isVerified = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $verificationCode = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $verificationExpiresAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $registrationEnabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiresAt = null;

    #[ORM\OneToOne(mappedBy: 'owner', targetEntity: SmtpSettings::class, cascade: ['persist', 'remove'])]
    private ?SmtpSettings $smtpSettings = null;

    #[ORM\OneToOne(mappedBy: 'owner', targetEntity: ResellerBranding::class, cascade: ['persist', 'remove'])]
    private ?ResellerBranding $branding = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatar = null;

    #[ORM\OneToOne(mappedBy: 'owner', targetEntity: CloudflareSettings::class, cascade: ['persist', 'remove'])]
    private ?CloudflareSettings $cloudflareSettings = null;

    #[ORM\ManyToOne(targetEntity: SubscriptionPackage::class)]
    #[ORM\JoinColumn(name: 'subscription_package_id', referencedColumnName: 'id', onDelete: 'SET NULL')]
    private ?SubscriptionPackage $subscriptionPackage = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $subscriptionExpiresAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $monthlyMessageCount = 0;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastMessageResetDate = null;

    #[ORM\Column(length: 20, options: ['default' => 'dark'])]
    private string $theme = 'dark';

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isTotpAuthenticationEnabled = false;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->facebookConnections = new ArrayCollection();
        $this->instagramConnections = new ArrayCollection();
    }


    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): static
    {
        $this->parent = $parent;
        return $this;
    }

    public function getTeamRole(): ?TeamRole
    {
        return $this->teamRole;
    }

    public function setTeamRole(?TeamRole $teamRole): static
    {
        $this->teamRole = $teamRole;
        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function getCreatedBy(): ?self
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?self $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getAccountType(): string
    {
        return $this->accountType;
    }

    public function setAccountType(string $accountType): static
    {
        $this->accountType = $accountType;
        return $this;
    }

    public function isTeamEnabled(): bool
    {
        if ($this->accountType === 'admin') {
            return true;
        }
        return $this->teamEnabled;
    }

    public function setTeamEnabled(bool $teamEnabled): static
    {
        $this->teamEnabled = $teamEnabled;
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

    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    public function setAvatar(?string $avatar): static
    {
        $this->avatar = $avatar;
        return $this;
    }

    public function getFacebookConnections(): Collection
    {
        return $this->facebookConnections;
    }

    public function getInstagramConnections(): Collection
    {
        return $this->instagramConnections;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function getVerificationCode(): ?string
    {
        return $this->verificationCode;
    }

    public function setVerificationCode(?string $verificationCode): static
    {
        $this->verificationCode = $verificationCode;

        return $this;
    }

    public function getVerificationExpiresAt(): ?\DateTimeInterface
    {
        return $this->verificationExpiresAt;
    }

    public function setVerificationExpiresAt(?\DateTimeInterface $verificationExpiresAt): static
    {
        $this->verificationExpiresAt = $verificationExpiresAt;

        return $this;
    }

    public function getSmtpSettings(): ?SmtpSettings
    {
        return $this->smtpSettings;
    }

    public function setSmtpSettings(?SmtpSettings $smtpSettings): static
    {
        // unset the owning side of the relation if necessary
        if ($smtpSettings === null && $this->smtpSettings !== null) {
            $this->smtpSettings->setOwner(null);
        }

        // set the owning side of the relation if necessary
        if ($smtpSettings !== null && $smtpSettings->getOwner() !== $this) {
            $smtpSettings->setOwner($this);
        }

        $this->smtpSettings = $smtpSettings;

        return $this;
    }

    public function isRegistrationEnabled(): bool
    {
        return $this->registrationEnabled;
    }

    public function setRegistrationEnabled(bool $registrationEnabled): static
    {
        $this->registrationEnabled = $registrationEnabled;
        return $this;
    }

    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): static
    {
        $this->resetToken = $resetToken;
        return $this;
    }

    public function getResetTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->resetTokenExpiresAt;
    }

    public function setResetTokenExpiresAt(?\DateTimeImmutable $resetTokenExpiresAt): static
    {
        $this->resetTokenExpiresAt = $resetTokenExpiresAt;
        return $this;
    }

    public function getBranding(): ?ResellerBranding
    {
        return $this->branding;
    }

    public function setBranding(?ResellerBranding $branding): static
    {
        // unset the owning side of the relation if necessary
        if ($branding === null && $this->branding !== null) {
            $this->branding->setOwner(null);
        }

        // set the owning side of the relation if necessary
        if ($branding !== null && $branding->getOwner() !== $this) {
            $branding->setOwner($this);
        }

        $this->branding = $branding;

        return $this;
    }

    public function getCloudflareSettings(): ?CloudflareSettings
    {
        return $this->cloudflareSettings;
    }

    public function setCloudflareSettings(?CloudflareSettings $cloudflareSettings): static
    {
        // unset the owning side of the relation if necessary
        if ($cloudflareSettings === null && $this->cloudflareSettings !== null) {
            $this->cloudflareSettings->setOwner(null);
        }

        // set the owning side of the relation if necessary
        if ($cloudflareSettings !== null && $cloudflareSettings->getOwner() !== $this) {
            $cloudflareSettings->setOwner($this);
        }

        $this->cloudflareSettings = $cloudflareSettings;

        return $this;
    }

    public function getSubscriptionPackage(): ?SubscriptionPackage
    {
        return $this->subscriptionPackage;
    }

    public function setSubscriptionPackage(?SubscriptionPackage $subscriptionPackage): static
    {
        $this->subscriptionPackage = $subscriptionPackage;

        return $this;
    }

    public function getSubscriptionExpiresAt(): ?\DateTimeInterface
    {
        return $this->subscriptionExpiresAt;
    }

    public function setSubscriptionExpiresAt(?\DateTimeInterface $subscriptionExpiresAt): static
    {
        $this->subscriptionExpiresAt = $subscriptionExpiresAt;

        return $this;
    }

    public function getMonthlyMessageCount(): int
    {
        return $this->monthlyMessageCount;
    }

    public function setMonthlyMessageCount(int $count): static
    {
        $this->monthlyMessageCount = $count;

        return $this;
    }

    public function getLastMessageResetDate(): ?\DateTimeInterface
    {
        return $this->lastMessageResetDate;
    }

    public function setLastMessageResetDate(?\DateTimeInterface $date): static
    {
        $this->lastMessageResetDate = $date;

        return $this;
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): static
    {
        $this->theme = $theme;
        return $this;
    }

    /**
     * Helper: check if user's subscription package grants access to the given module.
     * Module keys: 'whatsapp', 'facebook', 'ai_copilot', 'ecommerce'
     */
    public function hasModuleAccess(string $module): bool
    {
        $package = $this->subscriptionPackage;
        if (!$package) {
            return true; // No package = unrestricted
        }
        $features = $package->getFeatures();
        $modules = $features['modules'] ?? null;
        if ($modules === null) {
            return true; // No module restrictions configured
        }
        return in_array($module, $modules, true);
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpSecret && $this->isTotpAuthenticationEnabled;
    }

    public function setIsTotpAuthenticationEnabled(bool $isTotpAuthenticationEnabled): self
    {
        $this->isTotpAuthenticationEnabled = $isTotpAuthenticationEnabled;
        return $this;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?\Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface
    {
        if (!$this->totpSecret) {
            return null;
        }

        return new \Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration($this->totpSecret, \Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $totpSecret): self
    {
        $this->totpSecret = $totpSecret;
        return $this;
    }
}
