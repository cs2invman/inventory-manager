<?php

namespace App\Entity;

use App\Repository\DiscordWebhookRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DiscordWebhookRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER', fields: ['identifier'])]
#[UniqueEntity(fields: ['identifier'], message: 'This webhook identifier already exists.')]
class DiscordWebhook
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Identifier cannot be blank.')]
    #[Assert\Regex(
        pattern: '/^[a-z0-9_]+$/',
        message: 'Identifier must contain only lowercase letters, numbers, and underscores.'
    )]
    private ?string $identifier = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Display name cannot be blank.')]
    #[Assert\Length(max: 100, maxMessage: 'Display name cannot be longer than {{ limit }} characters.')]
    private ?string $displayName = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Webhook URL cannot be blank.')]
    #[Assert\Url(message: 'Webhook URL must be a valid URL.')]
    #[Assert\Regex(
        pattern: '/^https:\/\/discord\.com\/api\/webhooks\//',
        message: 'Webhook URL must start with https://discord.com/api/webhooks/'
    )]
    private ?string $webhookUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500, maxMessage: 'Description cannot be longer than {{ limit }} characters.')]
    private ?string $description = null;

    #[ORM\Column]
    private ?bool $isEnabled = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): static
    {
        $this->identifier = $identifier;
        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): static
    {
        $this->displayName = $displayName;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function setWebhookUrl(string $webhookUrl): static
    {
        $this->webhookUrl = $webhookUrl;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getIsEnabled(): ?bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): static
    {
        $this->isEnabled = $isEnabled;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function __toString(): string
    {
        return $this->displayName ?? $this->identifier ?? '';
    }
}
