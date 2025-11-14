<?php

namespace App\Service\Discord;

use App\Entity\DiscordConfig;
use App\Entity\DiscordNotification;
use App\Repository\DiscordConfigRepository;
use App\Repository\DiscordNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DiscordWebhookService
{
    private const COLOR_INFO = 0x3498db;    // Blue
    private const COLOR_SUCCESS = 0x2ecc71; // Green
    private const COLOR_WARNING = 0xf39c12; // Yellow
    private const COLOR_ERROR = 0xe74c3c;   // Red

    private const MAX_CONTENT_LENGTH = 2000;
    private const MAX_DESCRIPTION_LENGTH = 4096;
    private const MAX_FIELD_VALUE_LENGTH = 1024;
    private const MAX_FIELDS = 25;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly DiscordConfigRepository $configRepository,
        private readonly DiscordNotificationRepository $notificationRepository,
    ) {
    }

    /**
     * Send a simple text message to Discord.
     */
    public function sendMessage(string $configKey, string $content): bool
    {
        $webhookUrl = $this->getWebhookUrl($configKey);
        if (!$webhookUrl) {
            $this->logger->warning('Discord webhook URL not configured', [
                'config_key' => $configKey,
            ]);
            return false;
        }

        // Truncate content if needed
        $content = $this->truncateContent($content, self::MAX_CONTENT_LENGTH);

        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'json' => [
                    'content' => $content,
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 204 || $statusCode === 200) {
                $this->logNotification(
                    type: 'message',
                    channel: $configKey,
                    content: $content,
                    embed: [],
                    status: DiscordNotification::STATUS_SENT
                );
                return true;
            }

            $this->logNotification(
                type: 'message',
                channel: $configKey,
                content: $content,
                embed: [],
                status: DiscordNotification::STATUS_FAILED,
                error: "HTTP {$statusCode}: " . $response->getContent(false)
            );
            return false;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Discord webhook transport error', [
                'config_key' => $configKey,
                'error' => $e->getMessage(),
            ]);
            $this->logNotification(
                type: 'message',
                channel: $configKey,
                content: $content,
                embed: [],
                status: DiscordNotification::STATUS_FAILED,
                error: $e->getMessage()
            );
            return false;
        } catch (\Exception $e) {
            $this->logger->error('Discord webhook error', [
                'config_key' => $configKey,
                'error' => $e->getMessage(),
            ]);
            $this->logNotification(
                type: 'message',
                channel: $configKey,
                content: $content,
                embed: [],
                status: DiscordNotification::STATUS_FAILED,
                error: $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Send a rich embed message to Discord.
     *
     * @param array<mixed> $embed
     */
    public function sendEmbed(string $configKey, array $embed): bool
    {
        $webhookUrl = $this->getWebhookUrl($configKey);
        if (!$webhookUrl) {
            $this->logger->warning('Discord webhook URL not configured', [
                'config_key' => $configKey,
            ]);
            return false;
        }

        // Validate and sanitize embed
        $embed = $this->sanitizeEmbed($embed);

        try {
            $response = $this->httpClient->request('POST', $webhookUrl, [
                'json' => [
                    'embeds' => [$embed],
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode === 204 || $statusCode === 200) {
                $this->logNotification(
                    type: 'embed',
                    channel: $configKey,
                    content: $embed['title'] ?? '',
                    embed: $embed,
                    status: DiscordNotification::STATUS_SENT
                );
                return true;
            }

            $this->logNotification(
                type: 'embed',
                channel: $configKey,
                content: $embed['title'] ?? '',
                embed: $embed,
                status: DiscordNotification::STATUS_FAILED,
                error: "HTTP {$statusCode}: " . $response->getContent(false)
            );
            return false;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Discord webhook transport error', [
                'config_key' => $configKey,
                'error' => $e->getMessage(),
            ]);
            $this->logNotification(
                type: 'embed',
                channel: $configKey,
                content: $embed['title'] ?? '',
                embed: $embed,
                status: DiscordNotification::STATUS_FAILED,
                error: $e->getMessage()
            );
            return false;
        } catch (\Exception $e) {
            $this->logger->error('Discord webhook error', [
                'config_key' => $configKey,
                'error' => $e->getMessage(),
            ]);
            $this->logNotification(
                type: 'embed',
                channel: $configKey,
                content: $embed['title'] ?? '',
                embed: $embed,
                status: DiscordNotification::STATUS_FAILED,
                error: $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Send a system event notification with formatted embed.
     */
    public function sendSystemEvent(string $title, string $description, string $level = 'info'): bool
    {
        // Check if system events are enabled
        if (!$this->isNotificationEnabled('notify_system_events')) {
            $this->logger->info('System event notifications are disabled', [
                'title' => $title,
            ]);
            return false;
        }

        // Check rate limit
        if (!$this->checkRateLimit('system_event', 60)) {
            $this->logger->info('System event notification rate limited', [
                'title' => $title,
            ]);
            return false;
        }

        // Create embed
        $embed = $this->createEmbed(
            title: $title,
            description: $description,
            color: $this->getColorForLevel($level),
            fields: [
                [
                    'name' => 'Environment',
                    'value' => $_ENV['APP_ENV'] ?? 'unknown',
                    'inline' => true,
                ],
                [
                    'name' => 'Hostname',
                    'value' => gethostname() ?: 'unknown',
                    'inline' => true,
                ],
            ]
        );

        return $this->sendEmbed('webhook_system_events', $embed);
    }

    /**
     * Check if a notification type is enabled.
     */
    public function isNotificationEnabled(string $notificationType): bool
    {
        $config = $this->configRepository->findOneBy(['configKey' => $notificationType]);

        if (!$config) {
            return false;
        }

        return $config->getIsEnabled() && !empty($config->getConfigValue());
    }

    /**
     * Get webhook URL from configuration.
     */
    private function getWebhookUrl(string $configKey): ?string
    {
        $config = $this->configRepository->findOneBy(['configKey' => $configKey]);

        if (!$config || !$config->getIsEnabled()) {
            return null;
        }

        $url = $config->getConfigValue();

        // Basic validation
        if (empty($url) || !str_starts_with($url, 'https://discord.com/api/webhooks/')) {
            return null;
        }

        return $url;
    }

    /**
     * Create a Discord embed array.
     *
     * @param array<array{name: string, value: string, inline?: bool}> $fields
     * @return array<mixed>
     */
    private function createEmbed(
        string $title,
        string $description,
        ?int $color = null,
        array $fields = []
    ): array {
        $embed = [
            'title' => $this->truncateContent($title, 256),
            'description' => $this->truncateContent($description, self::MAX_DESCRIPTION_LENGTH),
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];

        if ($color !== null) {
            $embed['color'] = $color;
        }

        if (!empty($fields)) {
            // Limit fields
            $fields = array_slice($fields, 0, self::MAX_FIELDS);

            // Sanitize field values
            foreach ($fields as &$field) {
                $field['value'] = $this->truncateContent(
                    $field['value'],
                    self::MAX_FIELD_VALUE_LENGTH
                );
            }

            $embed['fields'] = $fields;
        }

        return $embed;
    }

    /**
     * Log notification to database.
     *
     * @param array<mixed> $embed
     */
    private function logNotification(
        string $type,
        string $channel,
        string $content,
        array $embed,
        string $status,
        ?string $error = null
    ): void {
        $notification = new DiscordNotification();
        $notification->setNotificationType($type);
        $notification->setChannelId($channel);
        $notification->setMessageContent($content);
        $notification->setEmbedData(!empty($embed) ? $embed : null);
        $notification->setStatus($status);

        if ($error) {
            $notification->setErrorMessage($error);
        }

        if ($status === DiscordNotification::STATUS_SENT) {
            $notification->setSentAt(new \DateTimeImmutable());
        }

        $this->entityManager->persist($notification);
        $this->entityManager->flush();
    }

    /**
     * Check rate limit to prevent duplicate notifications within a time window.
     */
    private function checkRateLimit(string $notificationType, int $minutes): bool
    {
        $threshold = new \DateTimeImmutable("-{$minutes} minutes");

        $recentNotifications = $this->notificationRepository->createQueryBuilder('n')
            ->where('n.notificationType = :type')
            ->andWhere('n.createdAt > :threshold')
            ->andWhere('n.status = :status')
            ->setParameter('type', $notificationType)
            ->setParameter('threshold', $threshold)
            ->setParameter('status', DiscordNotification::STATUS_SENT)
            ->getQuery()
            ->getResult();

        return count($recentNotifications) === 0;
    }

    /**
     * Get Discord color for log level.
     */
    private function getColorForLevel(string $level): int
    {
        return match (strtolower($level)) {
            'success' => self::COLOR_SUCCESS,
            'warning' => self::COLOR_WARNING,
            'error' => self::COLOR_ERROR,
            default => self::COLOR_INFO,
        };
    }

    /**
     * Sanitize embed to ensure it meets Discord's requirements.
     *
     * @param array<mixed> $embed
     * @return array<mixed>
     */
    private function sanitizeEmbed(array $embed): array
    {
        // Truncate title
        if (isset($embed['title'])) {
            $embed['title'] = $this->truncateContent($embed['title'], 256);
        }

        // Truncate description
        if (isset($embed['description'])) {
            $embed['description'] = $this->truncateContent(
                $embed['description'],
                self::MAX_DESCRIPTION_LENGTH
            );
        }

        // Limit and sanitize fields
        if (isset($embed['fields']) && is_array($embed['fields'])) {
            $embed['fields'] = array_slice($embed['fields'], 0, self::MAX_FIELDS);

            foreach ($embed['fields'] as &$field) {
                if (isset($field['value'])) {
                    $field['value'] = $this->truncateContent(
                        $field['value'],
                        self::MAX_FIELD_VALUE_LENGTH
                    );
                }
            }
        }

        return $embed;
    }

    /**
     * Truncate content to maximum length.
     */
    private function truncateContent(string $content, int $maxLength): string
    {
        if (mb_strlen($content) <= $maxLength) {
            return $content;
        }

        return mb_substr($content, 0, $maxLength - 3) . '...';
    }
}
