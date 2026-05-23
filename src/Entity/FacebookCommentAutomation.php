<?php

namespace App\Entity;

use App\Repository\FacebookCommentAutomationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FacebookCommentAutomationRepository::class)]
class FacebookCommentAutomation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Admin $owner = null;

    #[ORM\ManyToOne(inversedBy: 'commentAutomations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FacebookConnection $facebookConnection = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $postId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $campaignName = null;

    #[ORM\Column(length: 50, nullable: true, options: ['default' => 'generic'])]
    private ?string $automationMode = 'generic';

    #[ORM\Column(type: Types::BOOLEAN, nullable: true, options: ['default' => 0])]
    private ?bool $enableCommentReply = false;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $hideOrDelete = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $offensiveKeywords = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $offensivePrivateReplyFlow = null;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true, options: ['default' => 0])]
    private ?bool $sendReplyMultipleTimes = false;

    #[ORM\Column(type: Types::BOOLEAN, nullable: true, options: ['default' => 0])]
    private ?bool $hideCommentAfterReply = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $aiContextId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $genericCommentReply = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $genericPrivateReply = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $genericImageUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $genericVideoUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $fallbackCommentReply = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fallbackPrivateReply = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fallbackImageUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fallbackVideoUrl = null;

    #[ORM\OneToMany(mappedBy: 'automationCampaign', targetEntity: FacebookCommentAutomationRule::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $rules;

    public function __construct()
    {
        $this->rules = new ArrayCollection();
    }

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

    public function getFacebookConnection(): ?FacebookConnection
    {
        return $this->facebookConnection;
    }

    public function setFacebookConnection(?FacebookConnection $facebookConnection): static
    {
        $this->facebookConnection = $facebookConnection;
        return $this;
    }

    public function getPostId(): ?string
    {
        return $this->postId;
    }

    public function setPostId(?string $postId): static
    {
        $this->postId = $postId;
        return $this;
    }

    public function getCampaignName(): ?string
    {
        return $this->campaignName;
    }

    public function setCampaignName(?string $campaignName): static
    {
        $this->campaignName = $campaignName;
        return $this;
    }

    public function getAutomationMode(): ?string
    {
        return $this->automationMode;
    }

    public function setAutomationMode(?string $automationMode): static
    {
        $this->automationMode = $automationMode;
        return $this;
    }

    public function isEnableCommentReply(): ?bool
    {
        return $this->enableCommentReply;
    }

    public function setEnableCommentReply(?bool $enableCommentReply): static
    {
        $this->enableCommentReply = $enableCommentReply;
        return $this;
    }

    public function getHideOrDelete(): ?string
    {
        return $this->hideOrDelete;
    }

    public function setHideOrDelete(?string $hideOrDelete): static
    {
        $this->hideOrDelete = $hideOrDelete;
        return $this;
    }

    public function getOffensiveKeywords(): ?string
    {
        return $this->offensiveKeywords;
    }

    public function setOffensiveKeywords(?string $offensiveKeywords): static
    {
        $this->offensiveKeywords = $offensiveKeywords;
        return $this;
    }

    public function getOffensivePrivateReplyFlow(): ?string
    {
        return $this->offensivePrivateReplyFlow;
    }

    public function setOffensivePrivateReplyFlow(?string $offensivePrivateReplyFlow): static
    {
        $this->offensivePrivateReplyFlow = $offensivePrivateReplyFlow;
        return $this;
    }

    public function isSendReplyMultipleTimes(): ?bool
    {
        return $this->sendReplyMultipleTimes;
    }

    public function setSendReplyMultipleTimes(?bool $sendReplyMultipleTimes): static
    {
        $this->sendReplyMultipleTimes = $sendReplyMultipleTimes;
        return $this;
    }

    public function isHideCommentAfterReply(): ?bool
    {
        return $this->hideCommentAfterReply;
    }

    public function setHideCommentAfterReply(?bool $hideCommentAfterReply): static
    {
        $this->hideCommentAfterReply = $hideCommentAfterReply;
        return $this;
    }

    public function getAiContextId(): ?string
    {
        return $this->aiContextId;
    }

    public function setAiContextId(?string $aiContextId): static
    {
        $this->aiContextId = $aiContextId;
        return $this;
    }

    public function getGenericCommentReply(): ?string
    {
        return $this->genericCommentReply;
    }

    public function setGenericCommentReply(?string $genericCommentReply): static
    {
        $this->genericCommentReply = $genericCommentReply;
        return $this;
    }

    public function getGenericPrivateReply(): ?string
    {
        return $this->genericPrivateReply;
    }

    public function setGenericPrivateReply(?string $genericPrivateReply): static
    {
        $this->genericPrivateReply = $genericPrivateReply;
        return $this;
    }

    public function getGenericImageUrl(): ?string
    {
        return $this->genericImageUrl;
    }

    public function setGenericImageUrl(?string $genericImageUrl): static
    {
        $this->genericImageUrl = $genericImageUrl;
        return $this;
    }

    public function getGenericVideoUrl(): ?string
    {
        return $this->genericVideoUrl;
    }

    public function setGenericVideoUrl(?string $genericVideoUrl): static
    {
        $this->genericVideoUrl = $genericVideoUrl;
        return $this;
    }

    public function getFallbackCommentReply(): ?string
    {
        return $this->fallbackCommentReply;
    }

    public function setFallbackCommentReply(?string $fallbackCommentReply): static
    {
        $this->fallbackCommentReply = $fallbackCommentReply;
        return $this;
    }

    public function getFallbackPrivateReply(): ?string
    {
        return $this->fallbackPrivateReply;
    }

    public function setFallbackPrivateReply(?string $fallbackPrivateReply): static
    {
        $this->fallbackPrivateReply = $fallbackPrivateReply;
        return $this;
    }

    public function getFallbackImageUrl(): ?string
    {
        return $this->fallbackImageUrl;
    }

    public function setFallbackImageUrl(?string $fallbackImageUrl): static
    {
        $this->fallbackImageUrl = $fallbackImageUrl;
        return $this;
    }

    public function getFallbackVideoUrl(): ?string
    {
        return $this->fallbackVideoUrl;
    }

    public function setFallbackVideoUrl(?string $fallbackVideoUrl): static
    {
        $this->fallbackVideoUrl = $fallbackVideoUrl;
        return $this;
    }

    /**
     * @return Collection<int, FacebookCommentAutomationRule>
     */
    public function getRules(): Collection
    {
        return $this->rules;
    }

    public function addRule(FacebookCommentAutomationRule $rule): static
    {
        if (!$this->rules->contains($rule)) {
            $this->rules->add($rule);
            $rule->setAutomationCampaign($this);
        }

        return $this;
    }

    public function removeRule(FacebookCommentAutomationRule $rule): static
    {
        if ($this->rules->removeElement($rule)) {
            // set the owning side to null (unless already changed)
            if ($rule->getAutomationCampaign() === $this) {
                $rule->setAutomationCampaign(null);
            }
        }

        return $this;
    }

    public function getSettingsArray(): array
    {
        $settings = [
            'hideOrDelete' => $this->getHideOrDelete(),
            'offensiveKeywords' => $this->getOffensiveKeywords(),
            'offensivePrivateReplyFlowId' => $this->getOffensivePrivateReplyFlow(),
            'sendReplyMultipleTimes' => $this->isSendReplyMultipleTimes(),
            'enableCommentReply' => $this->isEnableCommentReply(),
            'hideCommentAfterReply' => $this->isHideCommentAfterReply(),
            'automationMode' => $this->getAutomationMode() ?: 'generic',
            'campaignName' => $this->getCampaignName(),
            'aiContextId' => $this->getAiContextId(),
            'privateReplyFlowId' => $this->getGenericPrivateReply(),
            'commentReplyText' => $this->getGenericCommentReply(),
            'imageReplyUrl' => $this->getGenericImageUrl(),
            'videoReplyUrl' => $this->getGenericVideoUrl(),
            'filterMatchType' => 'exact',
            'filterWords' => '',
            'filterRules' => [],
            'fallbackSettings' => [
                'commentReplyText' => $this->getFallbackCommentReply(),
                'privateReplyFlowId' => $this->getFallbackPrivateReply(),
                'imageReplyUrl' => $this->getFallbackImageUrl(),
                'videoReplyUrl' => $this->getFallbackVideoUrl(),
            ]
        ];

        $rules = [];
        foreach ($this->getRules() as $rule) {
            $rules[] = [
                'filterWords' => $rule->getFilterWords(),
                'filterMatchType' => $rule->getFilterMatchType(),
                'commentReplyText' => $rule->getCommentReplyText(),
                'privateReplyFlowId' => $rule->getPrivateReplyFlowId(),
                'imageReplyUrl' => $rule->getImageReplyUrl(),
                'videoReplyUrl' => $rule->getVideoReplyUrl(),
            ];
        }
        $settings['filterRules'] = $rules;

        if (count($rules) > 0) {
            $settings['filterWords'] = $rules[0]['filterWords'];
            $settings['filterMatchType'] = $rules[0]['filterMatchType'];
        }

        return $settings;
    }

    public function populateFromSettingsArray(array $settings): static
    {
        $this->setCampaignName($settings['campaignName'] ?? null);
        $this->setAutomationMode($settings['automationMode'] ?? 'generic');
        $this->setEnableCommentReply((bool)($settings['enableCommentReply'] ?? false));
        $this->setHideOrDelete($settings['hideOrDelete'] ?? null);
        $this->setOffensiveKeywords($settings['offensiveKeywords'] ?? null);
        $this->setOffensivePrivateReplyFlow($settings['offensivePrivateReplyFlowId'] ?? null);
        $this->setSendReplyMultipleTimes((bool)($settings['sendReplyMultipleTimes'] ?? false));
        $this->setHideCommentAfterReply((bool)($settings['hideCommentAfterReply'] ?? false));
        $this->setAiContextId($settings['aiContextId'] ?? null);

        $this->setGenericCommentReply($settings['commentReplyText'] ?? null);
        $this->setGenericPrivateReply($settings['privateReplyFlowId'] ?? null);
        $this->setGenericImageUrl($settings['imageReplyUrl'] ?? null);
        $this->setGenericVideoUrl($settings['videoReplyUrl'] ?? null);

        $fallback = $settings['fallbackSettings'] ?? [];
        $this->setFallbackCommentReply($fallback['commentReplyText'] ?? null);
        $this->setFallbackPrivateReply($fallback['privateReplyFlowId'] ?? null);
        $this->setFallbackImageUrl($fallback['imageReplyUrl'] ?? null);
        $this->setFallbackVideoUrl($fallback['videoReplyUrl'] ?? null);

        foreach ($this->getRules() as $existingRule) {
            $this->removeRule($existingRule);
        }

        $rules = $settings['filterRules'] ?? [];
        if (is_array($rules)) {
            foreach ($rules as $ruleData) {
                $rule = new FacebookCommentAutomationRule();
                $rule->setFilterWords($ruleData['filterWords'] ?? null);
                $rule->setFilterMatchType($ruleData['filterMatchType'] ?? 'exact');
                $rule->setCommentReplyText($ruleData['commentReplyText'] ?? null);
                $rule->setPrivateReplyFlowId($ruleData['privateReplyFlowId'] ?? null);
                $rule->setImageReplyUrl($ruleData['imageReplyUrl'] ?? null);
                $rule->setVideoReplyUrl($ruleData['videoReplyUrl'] ?? null);
                $this->addRule($rule);
            }
        }

        return $this;
    }
}
