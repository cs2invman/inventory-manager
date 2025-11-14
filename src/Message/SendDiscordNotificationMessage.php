<?php

namespace App\Message;

class SendDiscordNotificationMessage
{
    /**
     * @param array<mixed>|null $embed
     */
    public function __construct(
        private readonly string $notificationType,
        private readonly string $webhookConfigKey,
        private readonly string $content,
        private readonly ?array $embed = null,
    ) {
    }

    public function getNotificationType(): string
    {
        return $this->notificationType;
    }

    public function getWebhookConfigKey(): string
    {
        return $this->webhookConfigKey;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return array<mixed>|null
     */
    public function getEmbed(): ?array
    {
        return $this->embed;
    }
}
