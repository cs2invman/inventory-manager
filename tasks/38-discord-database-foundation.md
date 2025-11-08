# Discord Database Foundation

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Medium
**Created**: 2025-11-08

## Overview

Create the database entities and migrations to support Discord integration, including configuration storage, user account linking, and notification event tracking.

## Problem Statement

The CS2 Inventory system needs to integrate with Discord for notifications and bot commands. This requires storing Discord-specific configuration (webhooks, channels, bot settings), linking Discord users to application users, and tracking notification events to prevent duplicates and enable audit trails.

## Requirements

### Functional Requirements
- Store Discord configuration (webhook URLs, channel IDs, notification toggles)
- Link Discord accounts to User entities (Discord ID → User mapping)
- Track notification events (what was sent, when, to which channel)
- Support multiple webhook configurations for different notification types
- Allow enabling/disabling specific notification types

### Non-Functional Requirements
- Efficient lookups by Discord user ID for bot command authorization
- Audit trail for all Discord notifications sent
- Prevent duplicate notifications within configurable time windows
- Support future expansion (multiple Discord servers, role-based permissions)

## Technical Approach

### Database Changes

#### New Entity: `DiscordConfig`
Stores system-wide Discord configuration settings.

**Fields:**
- `id` (int, primary key, auto-increment)
- `configKey` (string, unique, 100) - e.g., "bot_token", "webhook_system_events", "channel_notifications"
- `configValue` (text, nullable) - JSON or plain text value
- `description` (string, 255, nullable) - Human-readable description for admin UI
- `isEnabled` (boolean, default true) - Toggle configuration on/off
- `createdAt` (datetime)
- `updatedAt` (datetime)

**Indexes:**
- Unique index on `configKey`

**Usage Examples:**
- `bot_token` → encrypted bot token
- `webhook_system_events` → webhook URL for system notifications
- `channel_notifications` → Discord channel ID for general notifications
- `notify_system_events` → boolean (enable/disable system event notifications)

#### New Entity: `DiscordUser`
Links Discord accounts to application users.

**Fields:**
- `id` (int, primary key, auto-increment)
- `user` (ManyToOne → User, nullable=false) - Application user
- `discordId` (string, unique, 20) - Discord user snowflake ID (e.g., "123456789012345678")
- `discordUsername` (string, 100) - Discord username (for display, can change)
- `discordDiscriminator` (string, 4, nullable) - Legacy discriminator (if applicable)
- `isVerified` (boolean, default false) - Admin verification status
- `linkedAt` (datetime) - When the link was created
- `verifiedAt` (datetime, nullable) - When admin verified the link
- `lastCommandAt` (datetime, nullable) - Last bot command used

**Indexes:**
- Unique index on `discordId`
- Index on `user_id` for reverse lookups

**Relationships:**
- `user` → User (many-to-one, required)

#### New Entity: `DiscordNotification`
Audit log of all Discord notifications sent.

**Fields:**
- `id` (int, primary key, auto-increment)
- `notificationType` (string, 50) - e.g., "system_event", "price_alert", "inventory_import"
- `channelId` (string, 20, nullable) - Discord channel ID where sent
- `webhookUrl` (string, 255, nullable) - Webhook URL used (hashed for privacy)
- `messageContent` (text) - Content of the message sent
- `embedData` (json, nullable) - Discord embed JSON (if used)
- `status` (string, 20) - "sent", "failed", "queued"
- `errorMessage` (text, nullable) - Error details if failed
- `sentAt` (datetime, nullable) - When successfully sent
- `createdAt` (datetime) - When queued/created

**Indexes:**
- Index on `notificationType` for filtering
- Index on `status` for retry logic
- Index on `createdAt` for cleanup queries

**Usage:**
- Audit trail of all notifications
- Prevent duplicate notifications (check recent entries)
- Retry failed notifications
- Analytics (notification frequency, types, success rates)

### Migration Details

Create single migration file: `Version[TIMESTAMP].php`

**Migration Up:**
1. Create `discord_config` table
2. Create `discord_user` table with foreign key to `user`
3. Create `discord_notification` table
4. Add default config entries:
   - `notify_system_events` → "true"
   - `system_events_rate_limit_minutes` → "60" (prevent spam)

**Migration Down:**
1. Drop `discord_notification` table
2. Drop `discord_user` table
3. Drop `discord_config` table

## Implementation Steps

1. **Create DiscordConfig Entity**
   - Create `src/Entity/DiscordConfig.php`
   - Add Doctrine annotations (ORM mapping)
   - Add validation constraints (NotBlank on configKey)
   - Add `__toString()` method returning configKey
   - Add helper methods: `getValueAsJson()`, `setValueAsJson()`

2. **Create DiscordUser Entity**
   - Create `src/Entity/DiscordUser.php`
   - Add relationship to User entity
   - Add unique constraint on discordId
   - Add validation (Discord ID must be numeric string, 17-20 chars)
   - Add `__toString()` method returning Discord username

3. **Create DiscordNotification Entity**
   - Create `src/Entity/DiscordNotification.php`
   - Add JSON type for embedData field
   - Add status enum or string with validation
   - Add helper methods: `markAsSent()`, `markAsFailed()`

4. **Update User Entity**
   - Add OneToOne relationship to DiscordUser
   - Add `getDiscordUser(): ?DiscordUser`
   - No cascade operations (DiscordUser is the owning side)

5. **Create Migration**
   - Run: `docker compose exec php php bin/console make:migration`
   - Review generated migration SQL
   - Test migration up/down locally
   - Add default config seed data in migration up

6. **Create Repository Methods**
   - `DiscordConfigRepository::findByKey(string $key): ?DiscordConfig`
   - `DiscordConfigRepository::getEnabledConfigs(): array`
   - `DiscordUserRepository::findByDiscordId(string $discordId): ?DiscordUser`
   - `DiscordUserRepository::findUnverified(): array`
   - `DiscordNotificationRepository::findRecentByType(string $type, int $minutes): array`
   - `DiscordNotificationRepository::findFailedNotifications(): array`

7. **Add Default Configuration**
   - Create data fixture or migration seed for default Discord configs
   - Include descriptive text for admin UI

## Edge Cases & Error Handling

- **Duplicate Discord ID linking**: Unique constraint prevents multiple users linking same Discord account
- **Orphaned DiscordUser records**: Consider cascade delete when User is deleted, or keep for audit trail
- **Config value encryption**: Bot token should be encrypted at rest (use Symfony's secrets or encrypt before storing)
- **Long message content**: Discord has 2000 char limit, ensure messageContent can store full text for audit
- **Channel/Webhook changes**: Old notifications retain old channel IDs for historical accuracy
- **Migration rollback**: Ensure down() method cleanly removes all tables and foreign keys

## Dependencies

### Blocking Dependencies
- None (this is the foundation task)

### Related Tasks (Discord Integration Feature)
- Task 39: Discord webhook service (depends on this)
- Task 40: Discord bot foundation with DiscordPHP (depends on this)
- Task 41: Discord bot commands implementation (depends on 40)
- Task 42: Discord admin settings UI (depends on this)
- Task 43: DISCORD.md documentation (depends on all above)

### Can Be Done in Parallel With
- None (other tasks depend on this foundation)

### External Dependencies
- Doctrine ORM (already in project)
- MariaDB 11.x (already in project)

## Acceptance Criteria

- [ ] DiscordConfig entity created with all specified fields
- [ ] DiscordUser entity created with User relationship
- [ ] DiscordNotification entity created with audit fields
- [ ] Migration file created and reviewed
- [ ] Migration runs successfully: `docker compose exec php php bin/console doctrine:migrations:migrate`
- [ ] Migration rollback works: `doctrine:migrations:migrate prev`
- [ ] Default configuration entries seeded in migration
- [ ] All repository methods implemented and tested manually
- [ ] User entity updated with DiscordUser relationship
- [ ] Foreign key constraints properly set up
- [ ] Unique constraints prevent duplicate Discord IDs
- [ ] Can manually insert test data via console or SQL

## Manual Verification Steps

```bash
# Run migration
docker compose exec php php bin/console doctrine:migrations:migrate

# Verify tables created
docker compose exec mariadb mysql -u cs2_user -p cs2_inventory -e "SHOW TABLES LIKE 'discord%';"

# Check default config entries
docker compose exec mariadb mysql -u cs2_user -p cs2_inventory -e "SELECT * FROM discord_config;"

# Test inserting a Discord user link (replace IDs)
docker compose exec php php bin/console dbal:run-sql "INSERT INTO discord_user (user_id, discord_id, discord_username, is_verified, linked_at) VALUES (1, '123456789012345678', 'TestUser#0000', 0, NOW());"

# Verify insert
docker compose exec mariadb mysql -u cs2_user -p cs2_inventory -e "SELECT * FROM discord_user;"

# Test rollback
docker compose exec php php bin/console doctrine:migrations:migrate prev

# Verify tables dropped
docker compose exec mariadb mysql -u cs2_user -p cs2_inventory -e "SHOW TABLES LIKE 'discord%';"

# Re-run migration for next tasks
docker compose exec php php bin/console doctrine:migrations:migrate
```

## Notes & Considerations

- **Security**: Bot token in DiscordConfig should be encrypted using Symfony secrets vault in production
- **Privacy**: Consider hashing webhook URLs in DiscordNotification table to avoid exposing full URLs in logs
- **Scalability**: If notification volume is high, consider partitioning discord_notification table by month
- **Future expansion**: Schema supports multiple Discord servers (add `guildId` field later if needed)
- **Discord username changes**: Store discordUsername for display, but always use discordId for linking (IDs never change)
- **Verification workflow**: isVerified flag allows admin approval before bot commands work (security measure)
- **Rate limiting**: Use DiscordNotification records to implement rate limits (e.g., max 5 notifications per type per hour)

## Related Tasks

- Task 39: Discord webhook service for outbound notifications (depends on this)
- Task 40: Discord bot foundation with DiscordPHP and Messenger (depends on this)
- Task 41: Discord bot !price command implementation (depends on Task 40)
- Task 42: Discord admin settings UI for configuration management (depends on this)
- Task 43: DISCORD.md documentation and CLAUDE.md updates (depends on all Discord tasks)
