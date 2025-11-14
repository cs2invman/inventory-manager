<?php

namespace App\MessageHandler;

use App\Message\SendDiscordNotificationMessage;
use App\Service\Discord\DiscordWebhookService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendDiscordNotificationHandler
{
    public function __construct(
        private readonly DiscordWebhookService $discordWebhookService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendDiscordNotificationMessage $message): void
    {
        $this->logger->info('Processing Discord notification', [
            'type' => $message->getNotificationType(),
            'config_key' => $message->getWebhookConfigKey(),
        ]);

        try {
            $success = false;

            if ($message->getEmbed() !== null) {
                $success = $this->discordWebhookService->sendEmbed(
                    $message->getWebhookConfigKey(),
                    $message->getEmbed()
                );
            } else {
                $success = $this->discordWebhookService->sendMessage(
                    $message->getWebhookConfigKey(),
                    $message->getContent()
                );
            }

            if ($success) {
                $this->logger->info('Discord notification sent successfully', [
                    'type' => $message->getNotificationType(),
                ]);
            } else {
                $this->logger->warning('Discord notification failed to send', [
                    'type' => $message->getNotificationType(),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Discord notification handler error', [
                'type' => $message->getNotificationType(),
                'error' => $e->getMessage(),
            ]);

            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }
}
