<?php

namespace App\Entity;

use App\Repository\FacebookBotFlowRepository;
use Doctrine\ORM\Mapping as ORM;

use App\Entity\TenantAwareInterface;
use App\Entity\Admin;
use App\Entity\FacebookConnection;

#[ORM\Entity(repositoryClass: FacebookBotFlowRepository::class)]
class FacebookBotFlow implements TenantAwareInterface
{
    public const MATCH_EXACT = 'exact';
    public const MATCH_CONTAINS = 'contains';
    public const MATCH_STARTS_WITH = 'starts_with';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: "owner_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?Admin $owner = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $name = null;

    /**
     * Comma-separated, lowercased trigger keywords. Kept as a single column
     * for simplicity; parsed at runtime by the Executor.
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $triggerKeyword = null;

    #[ORM\Column(length: 20, options: ['default' => self::MATCH_EXACT])]
    private string $matchMode = self::MATCH_EXACT;

    /**
     * The new graph format `{format:'graph', nodes:[...], edges:[...], viewport:{...}}`.
     */
    #[ORM\Column(type: 'json')]
    private array $flowData = [];

    #[ORM\Column]
    private ?bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: FacebookConnection::class)]
    #[ORM\JoinColumn(name: "facebook_connection_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?FacebookConnection $facebookConnection = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTriggerKeyword(): ?string
    {
        return $this->triggerKeyword;
    }

    public function setTriggerKeyword(?string $triggerKeyword): static
    {
        $this->triggerKeyword = $triggerKeyword;
        return $this;
    }

    /**
     * @return string[] Lowercased, trimmed list of keywords
     */
    public function getKeywordList(): array
    {
        if (!$this->triggerKeyword) {
            return [];
        }
        $parts = array_map(
            static fn (string $k): string => strtolower(trim($k)),
            explode(',', $this->triggerKeyword)
        );
        return array_values(array_filter($parts, static fn (string $k): bool => $k !== ''));
    }

    public function getMatchMode(): string
    {
        return $this->matchMode;
    }

    public function setMatchMode(string $matchMode): static
    {
        if (!in_array($matchMode, [self::MATCH_EXACT, self::MATCH_CONTAINS, self::MATCH_STARTS_WITH], true)) {
            throw new \InvalidArgumentException("Invalid match mode: {$matchMode}");
        }
        $this->matchMode = $matchMode;
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

    /**
     * True if the flow body matches the user input under its match mode.
     */
    public function matches(string $userInput): bool
    {
        $needles = $this->getKeywordList();
        if (!$needles) {
            return false;
        }
        $haystack = strtolower(trim($userInput));
        foreach ($needles as $needle) {
            $hit = match ($this->matchMode) {
                self::MATCH_EXACT       => $haystack === $needle,
                self::MATCH_CONTAINS    => $needle !== '' && str_contains($haystack, $needle),
                self::MATCH_STARTS_WITH => $needle !== '' && str_starts_with($haystack, $needle),
                default                 => false,
            };
            if ($hit) {
                return true;
            }
        }
        return false;
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
