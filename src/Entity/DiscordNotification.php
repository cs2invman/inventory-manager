<?php

namespace App\Entity;

use App\Repository\DiscordNotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DiscordNotificationRepository::class)]
#[ORM\Index(name: 'IDX_NOTIFICATION_TYPE', columns: ['notification_type'])]
#[ORM\Index(name: 'IDX_NOTIFICATION_STATUS', columns: ['status'])]
#[ORM\Index(name: 'IDX_NOTIFICATION_CREATED', columns: ['created_at'])]
class DiscordNotification
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $notificationType = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $channelId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $webhookUrl = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $messageContent = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $embedData = null;

    #[ORM\Column(length: 20)]
    private ?string $status = self::STATUS_QUEUED;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->status = self::STATUS_QUEUED;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNotificationType(): ?string
    {
        return $this->notificationType;
    }

    public function setNotificationType(string $notificationType): static
    {
        $this->notificationType = $notificationType;
        return $this;
    }

    public function getChannelId(): ?string
    {
        return $this->channelId;
    }

    public function setChannelId(?string $channelId): static
    {
        $this->channelId = $channelId;
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

    public function getMessageContent(): ?string
    {
        return $this->messageContent;
    }

    public function setMessageContent(string $messageContent): static
    {
        $this->messageContent = $messageContent;
        return $this;
    }

    /**
     * @return array<mixed>|null
     */
    public function getEmbedData(): ?array
    {
        return $this->embedData;
    }

    /**
     * @param array<mixed>|null $embedData
     */
    public function setEmbedData(?array $embedData): static
    {
        $this->embedData = $embedData;
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

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;
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

    /**
     * Helper method to mark notification as sent.
     */
    public function markAsSent(): static
    {
        $this->status = self::STATUS_SENT;
        $this->sentAt = new \DateTimeImmutable();
        $this->errorMessage = null;
        return $this;
    }

    /**
     * Helper method to mark notification as failed.
     */
    public function markAsFailed(string $errorMessage): static
    {
        $this->status = self::STATUS_FAILED;
        $this->errorMessage = $errorMessage;
        return $this;
    }
}
