<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\TenantAwareInterface;
use App\Entity\Admin;
use App\Entity\HttpApi;
use App\Entity\Subscriber;

#[ORM\Entity]
#[ORM\Table(name: 'http_api_call_log')]
#[ORM\HasLifecycleCallbacks]
class HttpApiCallLog implements TenantAwareInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Admin::class)]
    #[ORM\JoinColumn(name: "owner_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?Admin $owner = null;

    #[ORM\ManyToOne(targetEntity: HttpApi::class)]
    #[ORM\JoinColumn(name: "http_api_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private ?HttpApi $httpApi = null;

    #[ORM\Column(length: 10)]
    private ?string $method = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $url = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $requestPayload = [];

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $responseStatus = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $responseHeaders = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\ManyToOne(targetEntity: Subscriber::class)]
    #[ORM\JoinColumn(name: "subscriber_id", referencedColumnName: "id", nullable: true, onDelete: "SET NULL")]
    private ?Subscriber $subscriber = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

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

    public function getHttpApi(): ?HttpApi
    {
        return $this->httpApi;
    }

    public function setHttpApi(?HttpApi $httpApi): static
    {
        $this->httpApi = $httpApi;
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

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getRequestPayload(): ?array
    {
        return $this->requestPayload ?: [];
    }

    public function setRequestPayload(?array $requestPayload): static
    {
        $this->requestPayload = $requestPayload;
        return $this;
    }

    public function getResponseStatus(): ?int
    {
        return $this->responseStatus;
    }

    public function setResponseStatus(?int $responseStatus): static
    {
        $this->responseStatus = $responseStatus;
        return $this;
    }

    public function getResponseHeaders(): ?array
    {
        return $this->responseHeaders ?: [];
    }

    public function setResponseHeaders(?array $responseHeaders): static
    {
        $this->responseHeaders = $responseHeaders;
        return $this;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function setResponseBody(?string $responseBody): static
    {
        $this->responseBody = $responseBody;
        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;
        return $this;
    }

    public function getSubscriber(): ?Subscriber
    {
        return $this->subscriber;
    }

    public function setSubscriber(?Subscriber $subscriber): static
    {
        $this->subscriber = $subscriber;
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

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }
}
