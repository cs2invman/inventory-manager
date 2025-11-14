# Discord Webhook Service for Outbound Notifications

**Status**: Completed
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-08

## Overview

Implement a Discord webhook service to send outbound notifications from the CS2 Inventory system to Discord channels. This service will handle system event notifications using simple HTTP webhooks (no bot required for outbound messages).

## Problem Statement

The system needs to notify administrators and users about important events (Steam data sync completion, errors, maintenance) via Discord. Webhooks provide a simple, reliable way to send messages without requiring a long-running bot process for outbound-only notifications.

## Requirements

### Functional Requirements
- Send formatted messages to Discord channels via webhooks
- Support rich embeds (colors, fields, timestamps)
- Send "System Event" notifications initially
- Store notification audit trail in DiscordNotification entity
- Respect rate limits (prevent notification spam)
- Gracefully handle webhook failures (log and retry)
- Configuration-driven (enable/disable notification types)

### Non-Functional Requirements
- Async message sending (don't block main request)
- Retry failed notifications (exponential backoff)
- Respect Discord rate limits (5 requests per 2 seconds per webhook)
- Log all notifications for audit and debugging
- Support multiple webhook URLs for different channels

## Technical Approach

### Service Layer

#### New Service: `DiscordWebhookService`
Location: `src/Service/Discord/DiscordWebhookService.php`

**Dependencies:**
- `HttpClientInterface` (Symfony HTTP client)
- `EntityManagerInterface` (for DiscordConfig, DiscordNotification)
- `LoggerInterface`
- `DiscordConfigRepository`
- `DiscordNotificationRepository`

**Methods:**
```php
// Send a simple text message
public function sendMessage(string $configKey, string $content): bool

// Send a rich embed message
public function sendEmbed(string $configKey, array $embed): bool

// Send system event notification
public function sendSystemEvent(string $title, string $description, string $level = 'info'): bool

// Check if notification type is enabled
public function isNotificationEnabled(string $notificationType): bool

// Get webhook URL from config
private function getWebhookUrl(string $configKey): ?string

// Create Discord embed array
private function createEmbed(string $title, string $description, string $color = null, array $fields = []): array

// Log notification to database
private function logNotification(string $type, string $channel, string $content, array $embed, string $status, ?string $error = null): void

// Check rate limit (prevent spam)
private function checkRateLimit(string $notificationType, int $minutes): bool
```

**Embed Color Mapping:**
- `info` → Blue (#3498db)
- `success` → Green (#2ecc71)
- `warning` → Yellow (#f39c12)
- `error` → Red (#e74c3c)

### Symfony Messenger Integration

#### New Message: `SendDiscordNotificationMessage`
Location: `src/Message/SendDiscordNotificationMessage.php`

**Properties:**
- `string $notificationType`
- `string $webhookConfigKey`
- `string $content`
- `?array $embed`

#### New Handler: `SendDiscordNotificationHandler`
Location: `src/MessageHandler/SendDiscordNotificationHandler.php`

**Responsibilities:**
- Receive async message from queue
- Call DiscordWebhookService to send notification
- Handle failures and retry logic
- Log results

### Configuration

#### New Config File: `config/packages/discord.yaml`
```yaml
discord:
    webhooks:
        system_events:
            config_key: 'webhook_system_events'
            enabled_config_key: 'notify_system_events'

    rate_limits:
        system_events: 60  # minutes between duplicate notifications

    retry:
        max_attempts: 3
        delay: 1000  # milliseconds
        multiplier: 2  # exponential backoff
```

#### Environment Variables (.env)
```
###> Discord Configuration ###
DISCORD_WEBHOOK_SYSTEM_EVENTS=https://discord.com/api/webhooks/...
###< Discord Configuration ###
```

### Messenger Transport

Update `config/packages/messenger.yaml`:
```yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
            discord: '%env(MESSENGER_TRANSPORT_DSN)%'

        routing:
            'App\Message\SendDiscordNotificationMessage': discord
```

## Implementation Steps

1. **Create DiscordWebhookService**
   - Create `src/Service/Discord/` directory
   - Create `DiscordWebhookService.php` with constructor DI
   - Implement `getWebhookUrl()` method (fetch from DiscordConfig)
   - Implement `isNotificationEnabled()` method (check config)
   - Implement `checkRateLimit()` using DiscordNotification table
   - Implement `logNotification()` to create DiscordNotification records

2. **Implement Message Sending Methods**
   - Implement `sendMessage()` for simple text messages
   - Implement `createEmbed()` helper for Discord embed format
   - Implement `sendEmbed()` for rich formatted messages
   - Use Symfony HttpClient with proper error handling
   - Respect Discord API response codes (429 rate limit, 404 invalid webhook)

3. **Implement System Event Notifications**
   - Implement `sendSystemEvent()` method
   - Create embed with title, description, timestamp, color
   - Add system info footer (environment, hostname)
   - Call via async Messenger message

4. **Create Messenger Message & Handler**
   - Create `src/Message/SendDiscordNotificationMessage.php`
   - Create `src/MessageHandler/SendDiscordNotificationHandler.php`
   - Implement `__invoke()` with retry logic
   - Log failures and update DiscordNotification status

5. **Create Configuration Files**
   - Create `config/packages/discord.yaml` with default settings
   - Update `.env` with webhook URL placeholder
   - Update `.env.example` with Discord section

6. **Seed Initial Discord Config**
   - Update migration from Task 38 OR create data fixture
   - Add default configs:
     - `webhook_system_events` → empty (to be filled by admin)
     - `notify_system_events` → true
     - `system_events_rate_limit_minutes` → 60

7. **Create Console Command for Testing**
   - Create `src/Command/Discord/TestWebhookCommand.php`
   - Usage: `app:discord:test-webhook [config-key] [message]`
   - Send test message to verify webhook configuration
   - Display success/failure and error details

8. **Integrate with Existing System Events**
   - Update `SyncItemsCommand` (src/Command/Steam/SyncItemsCommand.php)
   - Send notification when sync completes (file count, items synced, duration)
   - Send error notification if sync fails
   - Make notification async (dispatch Messenger message)

## Edge Cases & Error Handling

- **Missing webhook URL**: Log warning, skip notification, don't crash
- **Invalid webhook URL (404)**: Log error, mark notification as failed, don't retry
- **Rate limited by Discord (429)**: Respect retry-after header, exponential backoff
- **Network timeout**: Retry with exponential backoff (max 3 attempts)
- **Duplicate notifications**: Check DiscordNotification table for recent identical notifications
- **Disabled notification type**: Check config before sending, skip silently
- **Long message content**: Truncate content to Discord's 2000 char limit for description
- **Embed field limits**: Max 25 fields, max 1024 chars per field value
- **Malformed embed JSON**: Validate embed structure before sending

## Dependencies

### Blocking Dependencies
- Task 38: Discord database foundation (DiscordConfig, DiscordNotification entities)

### Related Tasks (Discord Integration Feature)
- Task 40: Discord bot foundation with DiscordPHP (parallel)
- Task 41: Discord bot !price command (depends on Task 40)
- Task 42: Discord admin settings UI (parallel, can manage webhook URLs)
- Task 43: DISCORD.md documentation (depends on all Discord tasks)

### Can Be Done in Parallel With
- Task 40: Discord bot foundation (both depend on Task 38)
- Task 42: Discord admin settings UI (UI can configure webhooks while service is built)

### External Dependencies
- Symfony HttpClient component (already in Symfony 7+)
- Symfony Messenger component (already in Symfony 7+)
- Discord Webhook API (https://discord.com/developers/docs/resources/webhook)

## Acceptance Criteria

- [ ] DiscordWebhookService created with all required methods
- [ ] SendDiscordNotificationMessage and Handler created
- [ ] Message sending uses Symfony HttpClient correctly
- [ ] Rich embeds formatted according to Discord API spec
- [ ] Rate limiting implemented (prevents duplicate notifications within configured window)
- [ ] All notifications logged to DiscordNotification table with status
- [ ] Failed notifications marked in database with error message
- [ ] Configuration file created (discord.yaml)
- [ ] Environment variable placeholders added to .env and .env.example
- [ ] Test console command created and working
- [ ] System event integration added to SyncItemsCommand
- [ ] Notifications sent asynchronously via Messenger
- [ ] Retry logic implemented with exponential backoff
- [ ] Discord API errors handled gracefully (404, 429, 500)

## Manual Verification Steps

### Setup
```bash
# 1. Create a Discord webhook in your server
# - Go to Server Settings → Integrations → Webhooks → New Webhook
# - Copy webhook URL

# 2. Add webhook URL to .env
echo "DISCORD_WEBHOOK_SYSTEM_EVENTS=https://discord.com/api/webhooks/YOUR_WEBHOOK_URL" >> .env.local

# 3. Store in database
docker compose exec php php bin/console dbal:run-sql "INSERT INTO discord_config (config_key, config_value, description, is_enabled, created_at, updated_at) VALUES ('webhook_system_events', 'https://discord.com/api/webhooks/YOUR_WEBHOOK_URL', 'Webhook for system event notifications', 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value);"
```

### Test Webhook Service
```bash
# Test simple message
docker compose exec php php bin/console app:discord:test-webhook webhook_system_events "Hello from CS2 Inventory!"

# Check Discord channel - message should appear

# Verify notification logged in database
docker compose exec mariadb mysql -u cs2_user -p cs2_inventory -e "SELECT * FROM discord_notification ORDER BY created_at DESC LIMIT 5;"
```

### Test System Event
```bash
# Trigger a Steam sync (should send notification when complete)
docker compose exec php php bin/console app:steam:sync-items

# Check Discord channel - should see system event embed with:
# - Blue color (info)
# - Title: "Steam Item Sync Completed"
# - Description with stats
# - Timestamp

# Verify notification in database
docker compose exec mariadb mysql -u cs2_user -p cs2_inventory -e "SELECT notification_type, status, created_at, sent_at FROM discord_notification WHERE notification_type='system_event' ORDER BY created_at DESC LIMIT 1;"
```

### Test Rate Limiting
```bash
# Send multiple rapid notifications
docker compose exec php php bin/console app:discord:test-webhook webhook_system_events "Test 1"
docker compose exec php php bin/console app:discord:test-webhook webhook_system_events "Test 2"

# Only one should send (if rate limit configured)
# Check database for queued vs sent
docker compose exec mariadb mysql -u cs2_user -p cs2_inventory -e "SELECT status, COUNT(*) FROM discord_notification GROUP BY status;"
```

### Test Error Handling
```bash
# Test with invalid webhook URL
docker compose exec php php bin/console dbal:run-sql "UPDATE discord_config SET config_value='https://discord.com/api/webhooks/invalid' WHERE config_key='webhook_system_events';"

docker compose exec php php bin/console app:discord:test-webhook webhook_system_events "This should fail"

# Verify error logged
docker compose exec mariadb mysql -u cs2_user -p cs2_inventory -e "SELECT status, error_message FROM discord_notification ORDER BY created_at DESC LIMIT 1;"

# Restore valid webhook
docker compose exec php php bin/console dbal:run-sql "UPDATE discord_config SET config_value='YOUR_VALID_WEBHOOK_URL' WHERE config_key='webhook_system_events';"
```

## Notes & Considerations

- **Webhook vs Bot**: Webhooks are simpler for outbound notifications, but can't receive commands (need bot for that in Task 40)
- **Security**: Webhook URLs are sensitive (like passwords), should not be logged in plaintext
- **Discord limits**:
  - Rate limit: 5 requests per 2 seconds per webhook
  - Message length: 2000 characters
  - Embed description: 4096 characters
  - Embed fields: max 25, each value max 1024 chars
- **Async processing**: Using Messenger ensures notifications don't slow down main application
- **Retry strategy**: Exponential backoff prevents hammering Discord on failures
- **Audit trail**: Every notification logged for compliance and debugging
- **Future expansion**: Easy to add new notification types (price alerts, inventory imports) by:
  1. Adding config entry for webhook URL
  2. Adding config entry for enabled toggle
  3. Calling `sendEmbed()` or `sendSystemEvent()` from relevant service

## Related Tasks

- Task 38: Discord database foundation - MUST be completed first (blocking)
- Task 40: Discord bot foundation with DiscordPHP and Messenger (parallel)
- Task 41: Discord bot !price command (depends on Task 40)
- Task 42: Discord admin settings UI for webhook management (parallel)
- Task 43: DISCORD.md documentation (depends on all Discord tasks)
