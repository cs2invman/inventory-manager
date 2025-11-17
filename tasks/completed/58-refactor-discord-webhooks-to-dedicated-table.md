# Refactor Discord Webhooks to Dedicated Table

**Status**: Completed
**Priority**: Medium
**Estimated Effort**: Medium
**Created**: 2025-11-17

## Overview

Refactor Discord webhook storage from the generic `discord_config` key-value table to a dedicated `DiscordWebhook` entity and table. This provides better type safety, easier management, and clearer separation of concerns between configuration settings and webhook endpoints.

## Problem Statement

Currently, Discord webhook URLs are stored in the `discord_config` table as key-value pairs (e.g., `webhook_system_events`). This approach has several limitations:

1. **Poor Type Safety**: Webhook URLs are stored as TEXT strings alongside boolean and numeric config values
2. **Limited Metadata**: Can't easily store webhook-specific fields like display names or descriptions
3. **Unclear Domain Model**: Webhooks are endpoints/integrations, not configuration settings
4. **Harder to Query**: Need string matching on `config_key` to find all webhooks
5. **Management Complexity**: CRUD operations require generic config handling instead of webhook-specific logic

A dedicated `discord_webhook` table with proper entity relationships makes the domain model clearer and enables better webhook management features.

## Requirements

### Functional Requirements
- Create `DiscordWebhook` entity with dedicated table
- Migrate existing webhook data from `discord_config` to new table
- Update `DiscordWebhookService` to use new entity
- Remove webhook entries from `discord_config` after migration
- Maintain full backward compatibility during migration
- All existing webhook functionality must continue to work

### Non-Functional Requirements
- Zero downtime migration (data migration in same transaction as schema change)
- No breaking changes to service API (method signatures remain the same)
- Preserve all existing webhook URLs and settings
- Clean rollback path via Doctrine down migration

## Technical Approach

### Database Changes

#### New Entity: `DiscordWebhook`
Location: `src/Entity/DiscordWebhook.php`

**Fields:**
- `id` (int, auto-increment primary key)
- `identifier` (string, unique, 50 chars) - Code reference like 'system_events'
- `displayName` (string, 100 chars) - Human-readable name like 'System Events Channel'
- `webhookUrl` (string, 255 chars) - Full Discord webhook URL
- `description` (text, nullable) - Optional description of webhook purpose
- `isEnabled` (boolean, default true) - Enable/disable toggle
- `createdAt` (datetime immutable)
- `updatedAt` (datetime immutable)

**Constraints:**
- Unique index on `identifier`
- Not null constraints on `identifier`, `webhookUrl`, `isEnabled`

**Validation:**
- `identifier`: Required, must match pattern `^[a-z0-9_]+$`
- `webhookUrl`: Required, must start with `https://discord.com/api/webhooks/`
- `displayName`: Required, max 100 chars
- `description`: Optional, max 500 chars

#### New Repository: `DiscordWebhookRepository`
Location: `src/Repository/DiscordWebhookRepository.php`

**Methods:**
```php
// Find webhook by identifier
public function findByIdentifier(string $identifier): ?DiscordWebhook

// Find all enabled webhooks
public function findAllEnabled(): array

// Check if identifier exists
public function identifierExists(string $identifier): bool
```

#### Migration: Create Table and Migrate Data
Location: `migrations/VersionYYYYMMDDHHMMSS.php`

**Steps:**
1. Create `discord_webhook` table with all fields and indexes
2. Copy webhook data from `discord_config`:
   - `webhook_system_events` → identifier: 'system_events', displayName: 'System Events'
3. Delete webhook entries from `discord_config` (WHERE `config_key` LIKE 'webhook_%')

**Migration Logic:**
```php
public function up(Schema $schema): void
{
    // Create table
    $this->addSql('CREATE TABLE discord_webhook (
        id INT AUTO_INCREMENT NOT NULL,
        identifier VARCHAR(50) NOT NULL,
        display_name VARCHAR(100) NOT NULL,
        webhook_url VARCHAR(255) NOT NULL,
        description VARCHAR(500) DEFAULT NULL,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE INDEX UNIQ_IDENTIFIER (identifier),
        PRIMARY KEY (id)
    ) DEFAULT CHARACTER SET utf8mb4');

    // Migrate existing webhooks
    $now = date('Y-m-d H:i:s');

    // Get webhook_system_events from discord_config
    $this->addSql("
        INSERT INTO discord_webhook (identifier, display_name, webhook_url, description, is_enabled, created_at, updated_at)
        SELECT
            REPLACE(config_key, 'webhook_', '') as identifier,
            CONCAT(UPPER(SUBSTRING(REPLACE(config_key, 'webhook_', ''), 1, 1)),
                   SUBSTRING(REPLACE(REPLACE(config_key, 'webhook_', ''), '_', ' '), 2)) as display_name,
            config_value as webhook_url,
            description,
            is_enabled,
            '{$now}' as created_at,
            '{$now}' as updated_at
        FROM discord_config
        WHERE config_key LIKE 'webhook_%'
        AND config_value IS NOT NULL
        AND config_value != ''
    ");

    // Remove webhook entries from discord_config
    $this->addSql("DELETE FROM discord_config WHERE config_key LIKE 'webhook_%'");
}

public function down(Schema $schema): void
{
    // Restore webhooks to discord_config before dropping table
    $this->addSql("
        INSERT INTO discord_config (config_key, config_value, description, is_enabled, created_at, updated_at)
        SELECT
            CONCAT('webhook_', identifier) as config_key,
            webhook_url as config_value,
            description,
            is_enabled,
            created_at,
            updated_at
        FROM discord_webhook
    ");

    // Drop table
    $this->addSql('DROP TABLE discord_webhook');
}
```

### Service Layer Changes

#### Update: `DiscordWebhookService`
Location: `src/Service/Discord/DiscordWebhookService.php`

**Changes:**

1. **Replace dependency** `DiscordConfigRepository` → `DiscordWebhookRepository`

2. **Update `getWebhookUrl()` method:**
```php
private function getWebhookUrl(string $identifier): ?string
{
    $webhook = $this->webhookRepository->findByIdentifier($identifier);

    if (!$webhook || !$webhook->getIsEnabled()) {
        return null;
    }

    $url = $webhook->getWebhookUrl();

    // Basic validation
    if (empty($url) || !str_starts_with($url, 'https://discord.com/api/webhooks/')) {
        return null;
    }

    return $url;
}
```

3. **Update `isNotificationEnabled()` method:**
```php
public function isNotificationEnabled(string $identifier): bool
{
    $webhook = $this->webhookRepository->findByIdentifier($identifier);

    if (!$webhook) {
        return false;
    }

    return $webhook->getIsEnabled() && !empty($webhook->getWebhookUrl());
}
```

4. **Update method signatures:**
   - Change parameter names from `$configKey` to `$identifier` for clarity
   - Update PHPDoc comments to reflect new entity

5. **Update all method calls:**
   - `sendMessage()`: `$configKey` → `$identifier`
   - `sendEmbed()`: `$configKey` → `$identifier`
   - `sendSystemEvent()`: Already uses identifier 'webhook_system_events' → change to 'system_events'

#### Update All Service Callers

**Files to update:**
- Any controllers, commands, or services calling `DiscordWebhookService` methods
- Search for: `webhook_system_events` → change to `system_events`

### Configuration Changes

Remove Discord webhook configuration from `config/packages/discord.yaml` if present (webhooks are now database-managed, not file-configured).

## Implementation Steps

1. **Create DiscordWebhook Entity**
   - Create `src/Entity/DiscordWebhook.php`
   - Add all fields with proper types, constraints, and validation
   - Add unique constraint on `identifier`
   - Add `__construct()` to set timestamps
   - Add getters and setters

2. **Create DiscordWebhookRepository**
   - Create `src/Repository/DiscordWebhookRepository.php`
   - Extend `ServiceEntityRepository`
   - Implement `findByIdentifier()` method
   - Implement `findAllEnabled()` method
   - Implement `identifierExists()` method

3. **Generate and Customize Migration**
   - Run: `docker compose exec php php bin/console make:migration`
   - Edit migration file to add data migration logic
   - Add INSERT statement to copy webhooks from `discord_config`
   - Add DELETE statement to remove webhook entries from `discord_config`
   - Test up() and down() migrations

4. **Update DiscordWebhookService Constructor**
   - Remove `DiscordConfigRepository` dependency
   - Add `DiscordWebhookRepository` dependency
   - Update property declarations

5. **Refactor getWebhookUrl() Method**
   - Replace `findOneBy(['configKey' => $identifier])` with `findByIdentifier($identifier)`
   - Update to use `DiscordWebhook` entity methods
   - Keep validation logic (URL format check)

6. **Refactor isNotificationEnabled() Method**
   - Replace config lookup with webhook lookup
   - Use `DiscordWebhook->getIsEnabled()` and `getWebhookUrl()`

7. **Update Method Signatures and Docs**
   - Rename `$configKey` parameters to `$identifier`
   - Update PHPDoc comments
   - Update inline comments referencing "config"

8. **Update sendSystemEvent() Call**
   - Change `'webhook_system_events'` to `'system_events'`
   - Update any other hardcoded webhook identifiers

9. **Search and Replace Webhook Identifiers**
   - Find all calls to `DiscordWebhookService` methods
   - Replace `'webhook_system_events'` with `'system_events'`
   - Check test commands, message handlers, etc.

10. **Update Test Command**
    - Update `src/Command/Discord/TestWebhookCommand.php`
    - Change identifier from `'webhook_system_events'` to `'system_events'`

11. **Run Migration**
    - Execute: `docker compose exec php php bin/console doctrine:migrations:migrate`
    - Verify `discord_webhook` table created
    - Verify data migrated from `discord_config`
    - Verify webhook entries removed from `discord_config`

12. **Test Webhook Functionality**
    - Run: `docker compose exec php php bin/console app:discord:test-webhook system_events "Test message"`
    - Verify message sent successfully
    - Verify notification logged to `discord_notification` table

13. **Verify Database State**
    - Check `discord_webhook` table has correct data
    - Check `discord_config` table no longer has `webhook_*` entries
    - Verify constraints and indexes created

## Edge Cases & Error Handling

- **Empty webhook URL in discord_config**: Migration skips entries where `config_value IS NULL OR config_value = ''`
- **Duplicate identifiers**: Unique constraint prevents duplicates, migration uses INSERT (not INSERT IGNORE) to surface errors
- **Invalid webhook URLs**: Service validates URL format before sending
- **Webhook not found**: Service methods return `null` or `false` gracefully, log warning
- **Migration rollback**: Down migration restores data to `discord_config` before dropping table
- **Webhook disabled**: Service respects `isEnabled` flag and skips disabled webhooks
- **Special characters in identifier**: Entity validation restricts to `[a-z0-9_]` pattern

## Dependencies

### Blocking Dependencies
None - this is a refactoring task

### Related Tasks
- Task 42: Discord admin settings UI - will be updated to manage webhooks via new table
- Task 39: Discord webhook service (completed) - being refactored by this task
- Task 40: Discord bot foundation (completed) - uses discord_config but not for webhooks

### Can Be Done in Parallel With
- Any tasks not touching `DiscordWebhookService` or webhook configuration
- Task 41: Discord bot !price command (different service)

## Acceptance Criteria

- [ ] `DiscordWebhook` entity created with all required fields
- [ ] `DiscordWebhookRepository` created with finder methods
- [ ] Migration created to add `discord_webhook` table
- [ ] Migration includes data migration from `discord_config`
- [ ] Migration removes webhook entries from `discord_config`
- [ ] `DiscordWebhookService` updated to use `DiscordWebhookRepository`
- [ ] `getWebhookUrl()` method refactored to use new entity
- [ ] `isNotificationEnabled()` method refactored to use new entity
- [ ] All webhook identifier references updated (webhook_system_events → system_events)
- [ ] Test command updated and working
- [ ] Migration executed successfully
- [ ] Database schema matches entity definition
- [ ] Existing webhook data preserved and working
- [ ] No webhook entries remain in `discord_config` table
- [ ] Down migration successfully restores previous state
- [ ] All webhook functionality tested and working

## Manual Verification Steps

### 1. Check Current State (Before Migration)

```bash
# View current webhook in discord_config
docker compose exec mariadb mysql -u cs2_user -pcs2_password cs2_inventory -e "SELECT config_key, config_value, is_enabled FROM discord_config WHERE config_key LIKE 'webhook_%';"
```

Expected output:
```
+-------------------------+------------------------------------------+------------+
| config_key              | config_value                             | is_enabled |
+-------------------------+------------------------------------------+------------+
| webhook_system_events   | https://discord.com/api/webhooks/...     |          1 |
+-------------------------+------------------------------------------+------------+
```

### 2. Run Migration

```bash
# Generate migration (if not already created)
docker compose exec php php bin/console make:migration

# Review migration file
cat migrations/VersionYYYYMMDDHHMMSS.php

# Run migration
docker compose exec php php bin/console doctrine:migrations:migrate

# Should output:
# ++ migrating VersionYYYYMMDDHHMMSS
# -> CREATE TABLE discord_webhook...
# -> INSERT INTO discord_webhook...
# -> DELETE FROM discord_config...
# ++ migrated (Xms)
```

### 3. Verify New Table Structure

```bash
# Check table created
docker compose exec mariadb mysql -u cs2_user -pcs2_password cs2_inventory -e "DESCRIBE discord_webhook;"
```

Expected output:
```
+--------------+--------------+------+-----+---------+----------------+
| Field        | Type         | Null | Key | Default | Extra          |
+--------------+--------------+------+-----+---------+----------------+
| id           | int          | NO   | PRI | NULL    | auto_increment |
| identifier   | varchar(50)  | NO   | UNI | NULL    |                |
| display_name | varchar(100) | NO   |     | NULL    |                |
| webhook_url  | varchar(255) | NO   |     | NULL    |                |
| description  | varchar(500) | YES  |     | NULL    |                |
| is_enabled   | tinyint(1)   | NO   |     | 1       |                |
| created_at   | datetime     | NO   |     | NULL    |                |
| updated_at   | datetime     | NO   |     | NULL    |                |
+--------------+--------------+------+-----+---------+----------------+
```

### 4. Verify Data Migration

```bash
# Check webhooks migrated
docker compose exec mariadb mysql -u cs2_user -pcs2_password cs2_inventory -e "SELECT identifier, display_name, LEFT(webhook_url, 40) as webhook_preview, is_enabled FROM discord_webhook;"
```

Expected output:
```
+---------------+---------------+------------------------------------------+------------+
| identifier    | display_name  | webhook_preview                          | is_enabled |
+---------------+---------------+------------------------------------------+------------+
| system_events | System Events | https://discord.com/api/webhooks/...     |          1 |
+---------------+---------------+------------------------------------------+------------+
```

```bash
# Verify webhooks removed from discord_config
docker compose exec mariadb mysql -u cs2_user -pcs2_password cs2_inventory -e "SELECT COUNT(*) as webhook_count FROM discord_config WHERE config_key LIKE 'webhook_%';"
```

Expected output:
```
+---------------+
| webhook_count |
+---------------+
|             0 |
+---------------+
```

### 5. Test Webhook Functionality

```bash
# Test sending webhook message
docker compose exec php php bin/console app:discord:test-webhook system_events "Refactoring test message"

# Should output:
# Sending test message to webhook: system_events
# ✓ Message sent successfully
```

### 6. Verify Message in Discord

- Check your Discord channel
- Should see test message appear
- Verify message content and timestamp

### 7. Check Notification Log

```bash
# Verify notification logged
docker compose exec mariadb mysql -u cs2_user -pcs2_password cs2_inventory -e "SELECT notification_type, channel_id, status, created_at FROM discord_notification ORDER BY created_at DESC LIMIT 1;"
```

Expected output:
```
+-------------------+--------------+--------+---------------------+
| notification_type | channel_id   | status | created_at          |
+-------------------+--------------+--------+---------------------+
| message           | system_events| sent   | 2025-11-17 10:30:00 |
+-------------------+--------------+--------+---------------------+
```

### 8. Test Migration Rollback

```bash
# Rollback migration
docker compose exec php php bin/console doctrine:migrations:migrate prev

# Should output:
# -- reverting VersionYYYYMMDDHHMMSS
# -> INSERT INTO discord_config...
# -> DROP TABLE discord_webhook
# -- reverted (Xms)
```

```bash
# Verify webhooks restored to discord_config
docker compose exec mariadb mysql -u cs2_user -pcs2_password cs2_inventory -e "SELECT config_key, LEFT(config_value, 40) as value_preview, is_enabled FROM discord_config WHERE config_key LIKE 'webhook_%';"
```

Expected output:
```
+-------------------------+------------------------------------------+------------+
| config_key              | value_preview                            | is_enabled |
+-------------------------+------------------------------------------+------------+
| webhook_system_events   | https://discord.com/api/webhooks/...     |          1 |
+-------------------------+------------------------------------------+------------+
```

```bash
# Verify discord_webhook table dropped
docker compose exec mariadb mysql -u cs2_user -pcs2_password cs2_inventory -e "SHOW TABLES LIKE 'discord_webhook';"

# Should return empty result
```

### 9. Re-run Migration (Final State)

```bash
# Migrate forward again
docker compose exec php php bin/console doctrine:migrations:migrate

# Verify final state matches step 4
```

### 10. Test Edge Cases

```bash
# Test with disabled webhook
docker compose exec mariadb mysql -u cs2_user -pcs2_password cs2_inventory -e "UPDATE discord_webhook SET is_enabled = 0 WHERE identifier = 'system_events';"

docker compose exec php php bin/console app:discord:test-webhook system_events "Should not send"

# Should output:
# Discord webhook URL not configured or disabled

# Re-enable
docker compose exec mariadb mysql -u cs2_user -pcs2_password cs2_inventory -e "UPDATE discord_webhook SET is_enabled = 1 WHERE identifier = 'system_events';"
```

```bash
# Test with invalid identifier
docker compose exec php php bin/console app:discord:test-webhook nonexistent "Should fail"

# Should output:
# Discord webhook URL not configured
```

## Notes & Considerations

- **Clean separation of concerns**: Webhooks are integration endpoints, not configuration settings
- **Extensibility**: New entity makes it easier to add webhook-specific features (rate limiting per webhook, retry logic, etc.)
- **Type safety**: Dedicated entity provides better IDE support and type hints
- **Migration safety**: Up and down migrations both handle data migration to ensure clean rollback
- **Identifier naming**: Removed 'webhook_' prefix from identifiers for cleaner API (was 'webhook_system_events', now 'system_events')
- **UI preparation**: This refactoring prepares for Task 42 (admin UI) to manage webhooks properly
- **No functional changes**: This is a pure refactoring - all functionality remains identical
- **Testing**: No automated tests in this project, but manual verification steps cover all scenarios

## Related Tasks

- Task 39: Discord webhook service - COMPLETED, being refactored by this task
- Task 42: Discord admin settings UI - will manage webhooks via new entity (parallel, not blocking)
- Task 40: Discord bot foundation - COMPLETED, uses discord_config for bot config (not affected)
