# Discord Notification System

This document explains how the CS2 Inventory Management System integrates with Discord for sending notifications via webhooks.

## Overview

The Discord notification system sends real-time notifications to Discord channels for important events like:
- Steam item sync failures
- System errors
- Custom application events

**Key Features:**
- **Async processing**: Notifications are queued and processed asynchronously via Symfony Messenger
- **Rate limiting**: Prevents notification spam by limiting frequency
- **Notification history**: All notifications are logged in the database
- **Multiple webhook support**: Different webhooks for different notification types
- **Rich embeds**: Support for Discord rich embeds with colors, fields, and formatting
- **Error handling**: Failed notifications are logged with error details

## Architecture

### Message Flow

```
Application Code
    ↓
Create SendDiscordNotificationMessage
    ↓
Dispatch to Message Bus (async)
    ↓
Message stored in database (doctrine transport)
    ↓
Worker processes message (messenger:consume - scheduled via cron)
    ↓
SendDiscordNotificationHandler
    ↓
DiscordWebhookService
    ↓
Discord API
```

**Key Components:**
- **DiscordWebhookService**: Handles webhook validation, message formatting, and API communication
- **SendDiscordNotificationMessage**: Message object for async notification dispatch
- **SendDiscordNotificationHandler**: Processes queued notifications with retry logic
- **Database tables**: `discord_config` (webhook URLs), `discord_notification` (notification history)

## Setup Instructions

### 1. Create Discord Webhook

1. Open Discord and navigate to your server
2. Go to **Server Settings** → **Integrations** → **Webhooks**
3. Click **New Webhook** or **Create Webhook**
4. Configure the webhook:
   - **Name**: CS2 Inventory System Events (or your preferred name)
   - **Channel**: Select the channel where notifications should be sent
   - **Avatar**: Optional custom avatar for the webhook
5. Click **Copy Webhook URL** and save it for the next step

**Example webhook URL format:**
```
https://discord.com/api/webhooks/1234567890123456789/AbCdEfGhIjKlMnOpQrStUvWxYz1234567890
```

### 2. Configure Database Settings

The webhook URL must be stored in the `discord_config` table and enabled.

**Option A: Use SQL directly**

```sql
-- Update the webhook URL
UPDATE discord_config
SET config_value = 'https://discord.com/api/webhooks/YOUR_WEBHOOK_ID/YOUR_WEBHOOK_TOKEN',
    is_enabled = 1
WHERE config_key = 'webhook_system_events';
```

**Option B: Use MySQL CLI (via Docker)**

```bash
docker compose exec mariadb mysql -u cs2inventory -p cs2inventory -e "
UPDATE discord_config
SET config_value = 'https://discord.com/api/webhooks/YOUR_WEBHOOK_ID/YOUR_WEBHOOK_TOKEN',
    is_enabled = 1
WHERE config_key = 'webhook_system_events';
"
```

### 3. Test the Webhook

Send a test notification to verify everything is working:

```bash
docker compose exec php php bin/console app:discord:test-webhook webhook_system_events "Hello from CS2 Inventory!"
```

If successful, you should see the message in your Discord channel.

## Production Deployment

### Crontab Configuration

For production, use crontab to process the Discord notification queue regularly. The messenger worker should run every minute to ensure timely delivery of notifications.

Add the following to your crontab (edit with `crontab -e`):

```bash
# Process Discord notification queue every minute
# Processes for 55 seconds, then exits to avoid overlap
* * * * * cd /path/to/project && docker compose exec -T php php bin/console messenger:consume async --time-limit=55 >> /var/log/cs2inventory/messenger-worker.log 2>&1

# Steam item sync - every 5 minutes (optional, if not already scheduled)
*/5 * * * * cd /path/to/project && docker compose exec -T php php bin/console app:steam:sync-items >> /var/log/cs2inventory/steam-sync.log 2>&1
```

### Complete Crontab Example

Here's a complete example showing all CS2 Inventory cron jobs together:

```bash
# CS2 Inventory Management System - Crontab Configuration

# Discord notification queue worker - runs every minute
* * * * * cd /path/to/cs2inventory && docker compose exec -T php php bin/console messenger:consume async --time-limit=55 >> /var/log/cs2inventory/messenger.log 2>&1

# Steam item data sync - every 5 minutes
*/5 * * * * cd /path/to/cs2inventory && docker compose exec -T php php bin/console app:steam:sync-items >> /var/log/cs2inventory/steam-sync.log 2>&1

# Log cleanup - daily at 2 AM (optional)
0 2 * * * find /var/log/cs2inventory/*.log -mtime +30 -delete
```

### Important Notes

1. **Replace `/path/to/cs2inventory`** with your actual project path
2. **Replace `/path/to/project`** in the examples above with your actual path
3. **Create log directory:**
   ```bash
   sudo mkdir -p /var/log/cs2inventory
   sudo chown $(whoami):$(whoami) /var/log/cs2inventory
   ```

4. **Time limit calculation**: The `--time-limit=55` ensures the worker exits before the next cron run (1 minute), preventing overlapping processes

5. **Test your cron jobs** manually before adding to crontab:
   ```bash
   cd /path/to/project && docker compose exec -T php php bin/console messenger:consume async --time-limit=5
   ```

### Monitoring

To monitor the Discord notification queue:

```bash
# View recent notifications
docker compose exec mariadb mysql -u cs2inventory -p -e "
SELECT id, notification_type, status, created_at, sent_at
FROM discord_notification
ORDER BY created_at DESC
LIMIT 20;"

# Check for failed notifications
docker compose exec mariadb mysql -u cs2inventory -p -e "
SELECT id, notification_type, error_message, created_at
FROM discord_notification
WHERE status = 'failed'
ORDER BY created_at DESC
LIMIT 10;"

# View cron logs
tail -f /var/log/cs2inventory/messenger.log
```

## Best Practices

1. **Always use async dispatch** for notifications in user-facing code to avoid blocking requests
2. **Wrap Discord calls in try-catch** to prevent notification failures from breaking core functionality
3. **Use rate limiting** for notifications that could spam (configured in `discord_config` table)
4. **Monitor failed notifications** periodically using the queries above
5. **Keep webhook URLs secret** - stored only in database, never in version control
6. **Test webhook delivery** after any configuration changes
7. **Check cron logs** regularly to ensure the worker is processing messages
