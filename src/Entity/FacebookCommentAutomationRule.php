<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class FacebookCommentAutomationRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'rules')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FacebookCommentAutomation $automationCampaign = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $filterWords = null;

    #[ORM\Column(length: 50, nullable: true, options: ['default' => 'exact'])]
    private ?string $filterMatchType = 'exact';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentReplyText = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $privateReplyFlowId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageReplyUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $videoReplyUrl = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAutomationCampaign(): ?FacebookCommentAutomation
    {
        return $this->automationCampaign;
    }

    public function setAutomationCampaign(?FacebookCommentAutomation $automationCampaign): static
    {
        $this->automationCampaign = $automationCampaign;
        return $this;
    }

    public function getFilterWords(): ?string
    {
        return $this->filterWords;
    }

    public function setFilterWords(?string $filterWords): static
    {
        $this->filterWords = $filterWords;
        return $this;
    }

    public function getFilterMatchType(): ?string
    {
        return $this->filterMatchType;
    }

    public function setFilterMatchType(?string $filterMatchType): static
    {
        $this->filterMatchType = $filterMatchType;
        return $this;
    }

    public function getCommentReplyText(): ?string
    {
        return $this->commentReplyText;
    }

    public function setCommentReplyText(?string $commentReplyText): static
    {
        $this->commentReplyText = $commentReplyText;
        return $this;
    }

    public function getPrivateReplyFlowId(): ?string
    {
        return $this->privateReplyFlowId;
    }

    public function setPrivateReplyFlowId(?string $privateReplyFlowId): static
    {
        $this->privateReplyFlowId = $privateReplyFlowId;
        return $this;
    }

    public function getImageReplyUrl(): ?string
    {
        return $this->imageReplyUrl;
    }

    public function setImageReplyUrl(?string $imageReplyUrl): static
    {
        $this->imageReplyUrl = $imageReplyUrl;
        return $this;
    }

    public function getVideoReplyUrl(): ?string
    {
        return $this->videoReplyUrl;
    }

    public function setVideoReplyUrl(?string $videoReplyUrl): static
    {
        $this->videoReplyUrl = $videoReplyUrl;
        return $this;
    }
}
