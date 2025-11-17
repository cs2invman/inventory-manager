# Discord Bot Foundation with DiscordPHP and Symfony Messenger

**Status**: Not Started
**Priority**: High
**Estimated Effort**: Large
**Created**: 2025-11-08

## Overview

Set up the Discord bot infrastructure using DiscordPHP library, integrate with Symfony Messenger for async event processing, and create the foundation for handling bot commands. This enables users to interact with the CS2 Inventory system via Discord commands.

## Problem Statement

While webhooks (Task 39) handle outbound notifications, the system needs a persistent Discord bot to receive and respond to user commands (!price, !inventory, etc.). The bot must run as a long-lived process, handle Discord events, authenticate users via Discord ID linking, and route commands to appropriate handlers.

## Requirements

### Functional Requirements
- Install and configure DiscordPHP library
- Create long-running bot process managed by Symfony Messenger worker
- Listen for Discord message events in configured channels
- Parse command messages (starting with ! prefix)
- Authenticate users (verify Discord ID is linked to User account)
- Route commands to handlers
- Send responses back to Discord
- Graceful shutdown and restart capability
- Logging and error handling

### Non-Functional Requirements
- Bot process must be resilient (auto-restart on crash)
- Memory efficient (long-running process)
- Handle Discord rate limits gracefully
- Support multiple Discord servers (guilds) in future
- Secure: only respond to linked, verified users
- Log all command usage for audit

## Technical Approach

### Dependencies

#### Add DiscordPHP via Composer
```bash
docker compose run --rm php composer require team-reflex/discord-php:^10.0
```

**DiscordPHP version**: 10.x (supports Discord API v10)
**PHP requirement**: 8.1+ (project already on 8.4)

### Service Layer

#### New Service: `DiscordBotService`
Location: `src/Service/Discord/DiscordBotService.php`

**Purpose**: Core bot management, event listening, message parsing

**Dependencies:**
- `Discord\Discord` (DiscordPHP client)
- `EntityManagerInterface`
- `LoggerInterface`
- `DiscordConfigRepository`
- `DiscordUserRepository`
- `CommandRegistry` (new service for command routing)

**Methods:**
```php
// Initialize and start bot
public function start(): void

// Stop bot gracefully
public function stop(): void

// Register event handlers
private function registerEventHandlers(): void

// Handle incoming message
private function handleMessage(Message $message): void

// Parse command from message
private function parseCommand(string $content): ?array

// Check if user is authenticated
private function isUserAuthenticated(string $discordId): ?User

// Send response to Discord channel
public function sendResponse(string $channelId, string $content): void

// Send embed response
public function sendEmbedResponse(string $channelId, array $embed): void
```

#### New Service: `DiscordCommandRegistry`
Location: `src/Service/Discord/DiscordCommandRegistry.php`

**Purpose**: Register and route commands to handlers

**Methods:**
```php
// Register a command handler
public function register(string $command, DiscordCommandInterface $handler): void

// Get handler for command
public function getHandler(string $command): ?DiscordCommandInterface

// List all registered commands
public function getCommands(): array
```

#### New Interface: `DiscordCommandInterface`
Location: `src/Service/Discord/Command/DiscordCommandInterface.php`

**Purpose**: Contract for all Discord command handlers

**Methods:**
```php
// Execute command
public function execute(Message $message, array $args, User $user): string|array

// Get command name (e.g., "price")
public function getName(): string

// Get command description
public function getDescription(): string

// Get usage example
public function getUsage(): string

// Check if user has permission
public function hasPermission(User $user): bool
```

### Console Command

#### New Command: `DiscordBotCommand`
Location: `src/Command/Discord/DiscordBotCommand.php`

**Purpose**: Start Discord bot as console command

**Usage:**
```bash
docker compose exec php php bin/console app:discord:bot:start
```

**Implementation:**
- Runs in infinite loop
- Starts DiscordPHP client
- Registers event handlers
- Handles SIGTERM/SIGINT for graceful shutdown
- Memory limit monitoring (restart if approaching limit)

### Symfony Messenger Integration

#### New Message: `ProcessDiscordCommandMessage`
Location: `src/Message/ProcessDiscordCommandMessage.php`

**Properties:**
- `string $command`
- `array $args`
- `string $discordId`
- `string $channelId`
- `string $messageId`

**Purpose**: Async processing of Discord commands

#### New Handler: `ProcessDiscordCommandHandler`
Location: `src/MessageHandler/ProcessDiscordCommandHandler.php`

**Responsibilities:**
- Authenticate user by Discord ID
- Get command handler from registry
- Execute command
- Send response back to Discord
- Log command execution
- Handle errors

### Configuration

#### Update: `config/packages/discord.yaml`
```yaml
discord:
    bot:
        token: '%env(DISCORD_BOT_TOKEN)%'
        intents:
            - GUILDS
            - GUILD_MESSAGES
            - MESSAGE_CONTENT
        command_prefix: '!'
        allowed_channels: []  # empty = all channels, or specify channel IDs
        require_verification: true  # only respond to verified users

    commands:
        enabled: true
        rate_limit_per_user: 5  # max commands per minute per user
```

#### Update: `.env`
```env
###> Discord Bot Configuration ###
DISCORD_BOT_TOKEN=
###< Discord Bot Configuration ###
```

### Docker Service (Optional but Recommended)

#### Update: `compose.yml` (or `compose.dev.yml` for dev only)
```yaml
services:
  discord-bot:
    build: .
    container_name: cs2inventory_discord_bot
    command: php bin/console app:discord:bot:start
    depends_on:
      - mariadb
    environment:
      - APP_ENV=${APP_ENV}
      - DATABASE_URL=${DATABASE_URL}
      - DISCORD_BOT_TOKEN=${DISCORD_BOT_TOKEN}
    volumes:
      - .:/var/www/html
    restart: unless-stopped
    networks:
      - cs2inventory_network
```

**Alternative**: Use Symfony Messenger worker to run bot:
```yaml
services:
  messenger-discord:
    build: .
    command: php bin/console messenger:consume discord -vv
    # ... rest of config
```

## Implementation Steps

1. **Install DiscordPHP**
   - Run: `docker compose run --rm php composer require team-reflex/discord-php:^10.0`
   - Verify installation in composer.json and vendor/

2. **Create DiscordBotService**
   - Create `src/Service/Discord/DiscordBotService.php`
   - Inject Discord client (create via factory)
   - Implement bot initialization with token from config
   - Set required intents: GUILDS, GUILD_MESSAGES, MESSAGE_CONTENT

3. **Implement Event Handlers**
   - Register 'message' event handler
   - Filter out bot messages (prevent infinite loops)
   - Filter by command prefix (!)
   - Parse command and arguments
   - Validate user authentication (check DiscordUser table)

4. **Create Command Registry**
   - Create `src/Service/Discord/DiscordCommandRegistry.php`
   - Use Symfony service tagging for auto-registration
   - Tag interface: `discord.command`
   - Collect all tagged services in registry

5. **Create Command Interface**
   - Create `src/Service/Discord/Command/DiscordCommandInterface.php`
   - Define execute(), getName(), getDescription(), getUsage(), hasPermission()

6. **Create Base Command Class (Optional)**
   - Create `src/Service/Discord/Command/AbstractDiscordCommand.php`
   - Provide common functionality (error formatting, embed helpers)
   - Implement hasPermission() with default (verified user)

7. **Create Help Command**
   - Create `src/Service/Discord/Command/HelpCommand.php`
   - Implements DiscordCommandInterface
   - Usage: `!help` or `!help [command]`
   - Lists all available commands or shows details for specific command
   - Tag service: `discord.command`

8. **Create Console Command**
   - Create `src/Command/Discord/DiscordBotCommand.php`
   - Inject DiscordBotService
   - Call `$botService->start()` in execute()
   - Handle SIGTERM/SIGINT signals for graceful shutdown
   - Add memory monitoring (warn at 256MB, restart at 512MB)

9. **Implement Async Command Processing**
   - Create `src/Message/ProcessDiscordCommandMessage.php`
   - Create `src/MessageHandler/ProcessDiscordCommandHandler.php`
   - Route command execution through Messenger for async processing
   - Update DiscordBotService to dispatch messages instead of processing inline

10. **Create User Authentication Logic**
    - Implement `isUserAuthenticated()` in DiscordBotService
    - Check DiscordUser table for linked account
    - Check `isVerified` flag if `require_verification` is true
    - Return User entity or null

11. **Create Configuration Files**
    - Create/update `config/packages/discord.yaml`
    - Update `.env` and `.env.example`
    - Add service definitions in `config/services.yaml` if needed

12. **Add Command Logging**
    - Create helper in DiscordBotService: `logCommandExecution()`
    - Store in DiscordNotification table with type "command_execution"
    - Log: command, args, user, timestamp, success/failure

13. **Create Testing Command**
    - Create `src/Command/Discord/TestBotCommand.php`
    - Usage: `app:discord:test-bot`
    - Validates bot token, tests Discord connection, lists guilds
    - Does NOT start event loop (just tests connection)

## Edge Cases & Error Handling

- **Invalid bot token**: Catch exception, log error, don't crash - provide clear error message
- **Missing MESSAGE_CONTENT intent**: Bot can't read message content - document intent requirement in DISCORD.md
- **Unlinked user**: Respond with friendly message: "Please link your Discord account at [URL]"
- **Unverified user**: Respond: "Your account is pending verification by an admin"
- **Unknown command**: Respond: "Unknown command. Type !help for available commands"
- **Command handler exception**: Catch, log, respond with generic error, don't crash bot
- **Discord API rate limit**: DiscordPHP handles automatically, but log warnings
- **Memory leak**: Monitor memory usage, restart bot gracefully when approaching limit
- **Network disconnection**: DiscordPHP auto-reconnects, log reconnection events
- **Database connection lost**: Reconnect EntityManager, don't cache User entities long-term
- **Malformed command arguments**: Validate in command handler, respond with usage example

## Dependencies

### Blocking Dependencies
- Task 38: Discord database foundation (DiscordUser, DiscordConfig entities)

### Related Tasks (Discord Integration Feature)
- Task 39: Discord webhook service (parallel, independent)
- Task 41: Discord bot !price command (depends on this task)
- Task 42: Discord admin settings UI (parallel, manages linked users)
- Task 43: DISCORD.md documentation (depends on all Discord tasks)

### Can Be Done in Parallel With
- Task 39: Discord webhook service (both depend on Task 38, but are independent)
- Task 42: Discord admin settings UI (can manage users while bot is built)

### External Dependencies
- DiscordPHP library: team-reflex/discord-php ^10.0
- Discord Bot Application (must be created in Discord Developer Portal)
- Required Discord Bot Permissions:
  - Read Messages/View Channels
  - Send Messages
  - Embed Links
  - Read Message History
- Required Discord Bot Intents:
  - GUILDS (for server info)
  - GUILD_MESSAGES (for message events)
  - MESSAGE_CONTENT (privileged - required to read message text)

## Acceptance Criteria

- [ ] DiscordPHP installed via Composer
- [ ] DiscordBotService created with event handling
- [ ] DiscordCommandRegistry created with service tagging
- [ ] DiscordCommandInterface created
- [ ] HelpCommand created and registered
- [ ] Console command `app:discord:bot:start` created
- [ ] Bot connects to Discord successfully
- [ ] Bot responds to !help command
- [ ] User authentication works (checks DiscordUser table)
- [ ] Unlinked users get friendly error message
- [ ] Unverified users get pending verification message
- [ ] Unknown commands trigger help message
- [ ] All command executions logged to DiscordNotification table
- [ ] Bot handles SIGTERM gracefully (stops event loop)
- [ ] Memory monitoring implemented
- [ ] Configuration files created (discord.yaml, .env)
- [ ] Test command `app:discord:test-bot` created and working
- [ ] Async command processing via Messenger implemented

## Manual Verification Steps

### Setup Discord Bot Application

1. **Create Bot in Discord Developer Portal**
   - Go to https://discord.com/developers/applications
   - Create New Application → name it "CS2 Inventory Bot"
   - Go to "Bot" tab → Add Bot
   - Copy bot token → add to .env.local as DISCORD_BOT_TOKEN
   - Enable Privileged Gateway Intents:
     - ✅ MESSAGE CONTENT INTENT (required!)
     - ✅ SERVER MEMBERS INTENT (optional)

2. **Invite Bot to Server**
   - Go to OAuth2 → URL Generator
   - Select scopes: `bot`
   - Select permissions:
     - View Channels
     - Send Messages
     - Embed Links
     - Read Message History
   - Copy generated URL → open in browser → invite to test server

3. **Configure Database**
   ```bash
   # Store bot token in DiscordConfig
   docker compose exec php php bin/console dbal:run-sql "INSERT INTO discord_config (config_key, config_value, description, is_enabled, created_at, updated_at) VALUES ('bot_token', 'YOUR_BOT_TOKEN', 'Discord bot token', 1, NOW(), NOW()) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value);"
   ```

### Test Bot Connection

```bash
# Test bot connection (doesn't start event loop)
docker compose exec php php bin/console app:discord:test-bot

# Should output:
# ✓ Bot token valid
# ✓ Connected to Discord
# ✓ Bot user: CS2 Inventory Bot#1234
# ✓ In X guilds: [Server Name 1, Server Name 2]
```

### Start Bot

```bash
# Start bot in foreground (for testing)
docker compose exec php php bin/console app:discord:bot:start

# Should output:
# [INFO] Discord bot starting...
# [INFO] Registered commands: help
# [INFO] Logged in as CS2 Inventory Bot#1234
# [INFO] Ready to receive commands!
```

### Test Commands in Discord

1. **Test !help**
   - In Discord channel, type: `!help`
   - Bot should respond with list of available commands
   - Response should include: `!help` command

2. **Test Authentication (Unlinked User)**
   - Type: `!help` (from Discord account NOT linked to User)
   - Bot should respond: "Please link your Discord account at [URL]"

3. **Test Authentication (Linked User)**
   ```bash
   # Link your Discord account to User ID 1
   docker compose exec php php bin/console dbal:run-sql "INSERT INTO discord_user (user_id, discord_id, discord_username, is_verified, linked_at) VALUES (1, 'YOUR_DISCORD_ID', 'YourUsername#0000', 1, NOW());"

   # To get your Discord ID: Right-click your username in Discord → Copy ID (enable Developer Mode first)
   ```
   - Type: `!help` again
   - Bot should respond with command list (authenticated)

4. **Test Unknown Command**
   - Type: `!unknown`
   - Bot should respond: "Unknown command. Type !help for available commands"

### Verify Logging

```bash
# Check command execution logs
docker compose exec mariadb mysql -u cs2_user -p cs2_inventory -e "SELECT notification_type, message_content, status, created_at FROM discord_notification WHERE notification_type='command_execution' ORDER BY created_at DESC LIMIT 10;"
```

### Test Graceful Shutdown

```bash
# In one terminal, start bot
docker compose exec php php bin/console app:discord:bot:start

# In another terminal, send SIGTERM
docker compose exec php pkill -TERM -f "discord:bot:start"

# First terminal should show:
# [INFO] Received shutdown signal
# [INFO] Stopping Discord bot gracefully...
# [INFO] Bot stopped
```

### Test as Docker Service (Optional)

```bash
# If you added discord-bot service to compose.yml
docker compose up -d discord-bot

# Check logs
docker compose logs -f discord-bot

# Should see bot startup messages
# Bot should auto-restart if it crashes
```

## Notes & Considerations

- **Privileged Intent Required**: MESSAGE_CONTENT intent is privileged - must be enabled in Discord Developer Portal
- **Bot vs User**: Bot cannot execute commands sent by other bots (prevents bot loops)
- **Long-running Process**: Bot runs indefinitely - monitor memory usage and restart periodically
- **Async vs Sync**: Heavy commands (database queries, API calls) should be async via Messenger
- **Rate Limits**: Discord enforces rate limits per endpoint - DiscordPHP handles automatically
- **Intents**: Only request necessary intents - MESSAGE_CONTENT is required for reading message text
- **Sharding**: For large bots (2500+ servers), Discord requires sharding - not needed initially
- **Error Recovery**: DiscordPHP handles reconnections, but command handlers should be resilient
- **Testing**: Use separate bot token for dev/production to avoid conflicts
- **Command Prefix**: Configurable (! by default), could support slash commands in future

## Related Tasks

- Task 38: Discord database foundation - MUST be completed first (blocking)
- Task 39: Discord webhook service for outbound notifications (parallel)
- Task 41: Discord bot !price command - depends on this task (blocking)
- Task 42: Discord admin settings UI for user verification (parallel)
- Task 43: DISCORD.md documentation - depends on all Discord tasks
