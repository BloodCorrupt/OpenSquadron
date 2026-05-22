<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\TenantAwareInterface;
use App\Entity\Admin;

#[ORM\Entity]
#[ORM\Table(name: 'http_api')]
#[ORM\HasLifecycleCallbacks]
class HttpApi implements TenantAwareInterface
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

    #[ORM\Column(type: Types::TEXT)]
    private ?string $endpointUrl = null;

    #[ORM\Column(length: 10)]
    private ?string $method = 'GET';

    #[ORM\Column(length: 50)]
    private ?string $channel = 'global';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $testSubscriberId = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'active';

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $verified = false;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $headers = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $options = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $cookies = [];

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $bodyType = 'DEFAULT';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bodyData = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $totalCall = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $totalSuccess = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $totalError = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getEndpointUrl(): ?string
    {
        return $this->endpointUrl;
    }

    public function setEndpointUrl(string $endpointUrl): static
    {
        $this->endpointUrl = $endpointUrl;
        return $this;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function setMethod(string $method): static
    {
        $this->method = strtoupper($method);
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

    public function getTestSubscriberId(): ?string
    {
        return $this->testSubscriberId;
    }

    public function setTestSubscriberId(?string $testSubscriberId): static
    {
        $this->testSubscriberId = $testSubscriberId;
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

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function setVerified(bool $verified): static
    {
        $this->verified = $verified;
        return $this;
    }

    public function getHeaders(): ?array
    {
        return $this->headers ?: [];
    }

    public function setHeaders(?array $headers): static
    {
        $this->headers = $headers;
        return $this;
    }

    public function getOptions(): ?array
    {
        return $this->options ?: [];
    }

    public function setOptions(?array $options): static
    {
        $this->options = $options;
        return $this;
    }

    public function getCookies(): ?array
    {
        return $this->cookies ?: [];
    }

    public function setCookies(?array $cookies): static
    {
        $this->cookies = $cookies;
        return $this;
    }

    public function getBodyType(): ?string
    {
        return $this->bodyType;
    }

    public function setBodyType(?string $bodyType): static
    {
        $this->bodyType = $bodyType;
        return $this;
    }

    public function getBodyData(): ?string
    {
        return $this->bodyData;
    }

    public function setBodyData(?string $bodyData): static
    {
        $this->bodyData = $bodyData;
        return $this;
    }

    public function getTotalCall(): int
    {
        return $this->totalCall;
    }

    public function setTotalCall(int $totalCall): static
    {
        $this->totalCall = $totalCall;
        return $this;
    }

    public function incrementTotalCall(): static
    {
        $this->totalCall++;
        return $this;
    }

    public function getTotalSuccess(): int
    {
        return $this->totalSuccess;
    }

    public function setTotalSuccess(int $totalSuccess): static
    {
        $this->totalSuccess = $totalSuccess;
        return $this;
    }

    public function incrementTotalSuccess(): static
    {
        $this->totalSuccess++;
        return $this;
    }

    public function getTotalError(): int
    {
        return $this->totalError;
    }

    public function setTotalError(int $totalError): static
    {
        $this->totalError = $totalError;
        return $this;
    }

    public function incrementTotalError(): static
    {
        $this->totalError++;
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

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }
}
